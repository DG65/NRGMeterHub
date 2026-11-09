<?php

// ===========================================================================
// MeterHub — generisches Modbus-TCP-Framework für Energiezähler verschiedener
// Hersteller. Ein Modul, ein Auswahlfeld „Zählertyp" — je nach Auswahl werden
// die passenden Datenpunkt-Gruppen und Register freigeschaltet.
//
// Aufbau analog zu InverterHub:
//   MHUB_ModbusTcpClient        — gemeinsame Modbus-TCP-Grundfunktionen
//   MHUB_MeterDriverInterface   — Vertrag, den jeder Zähler-Treiber erfüllt
//   MHUB_Pac2200Driver          — Siemens SENTRON PAC2200
//   Umg604Driver           — Janitza UMG 604(-PRO)
//   MeterHub               — Hauptmodul, lädt den Treiber laut Meter-Property
//
// Zähler werden überwiegend nur gelesen (kein writeControl) — daher ist das
// Interface schlanker als beim InverterHub. Ausnahme seit 07.09.2026: die
// beiden blue'Log-Sollwertkanäle (RPC/Power Control, MHUB_BlueLogRpcDriver/
// MHUB_BlueLogPowerControlDriver) SCHREIBEN — über das optionale
// MHUB_WritableMeterDriverInterface, das rein lesende Treiber nicht berührt.
// ===========================================================================

// Gemeinsame Schnittstelle der beiden Verbindungswege (direkt/Gateway, siehe
// MHUB_ModbusGatewayClient weiter unten) — GetModbusClient() liefert je nach
// Property 'ConnectionMode' die eine oder die andere Klasse, Treibercode
// bleibt unverändert.
interface MHUB_ModbusClientInterface
{
    public function readHolding($startReg, $count);
    public function readInput($startReg, $count);
    public function writeHolding($startReg, array $regs): bool;
    public function close(): void;
    public function setWordSwap(bool $s);
}

/**
 * Texte zur Laufzeit übersetzen (Quelltexte englisch, Deutsch in locale.json — SUITE.md Punkt 9). Die Modulklasse
 * hinterlegt im Konstruktor ihre Translate()-Methode; auch Hilfsklassen und statische Methoden kommen so an die Übersetzung.
 * Mit Argumenten: sprintf() auf den übersetzten Text.
 */
class MHUB_Text
{
    private static $fn = null;

    public static function set(\Closure $fn): void
    {
        self::$fn = $fn;
    }

    public static function t(string $text, ...$args): string
    {
        $s = self::$fn !== null ? (string)(self::$fn)($text) : $text;
        return $args ? sprintf($s, ...$args) : $s;
    }
}

class MHUB_ModbusTcpClient implements MHUB_ModbusClientInterface
{
    public $host;
    public $port;
    public $unitId;

    public function __construct($host, $port, $unitId)
    {
        $this->host   = $host;
        $this->port   = $port;
        $this->unitId = $unitId;
    }

    // Read Holding Registers (FC 0x03). Beide unterstützten Zähler (PAC2200,
    // UMG604) legen ihre Messwerte auf Holding-Registern ab.
    public function readHolding($startReg, $count)
    {
        return $this->modbusRead(0x03, $startReg, $count);
    }

    // Read Input Registers (FC 0x04) — für Zähler, die Messwerte auf
    // Input-Registern führen (aktuell keiner, aber für künftige Treiber da).
    public function readInput($startReg, $count)
    {
        return $this->modbusRead(0x04, $startReg, $count);
    }

    // -----------------------------------------------------------------------
    // Eine Verbindung je Lesezyklus (Dietmars Auftrag 12.09.2026). Vorher
    // öffnete JEDE Anfrage eine eigene TCP-Verbindung und schloss sie sofort
    // wieder — im Solarpark standen dadurch 2 333 abgebaute Verbindungen zu
    // einem einzigen blue'Log (.204, 48 Wechselrichter-Instanzen) im
    // Wartezustand, rund 20 neue pro Sekunde. Genau schnelles Verbinden/
    // Trennen verkraften blue'Logs nachweislich schlecht (live gemessen
    // 09.09.2026, siehe MeterHubDiscovery). Jetzt: die erste Anfrage öffnet,
    // alle weiteren desselben Zyklus nutzen die Verbindung mit, MeterHub
    // schließt sie am Zyklusende (close(), sonst spätestens der Destruktor).
    // -----------------------------------------------------------------------

    /** @var resource|null offene Verbindung für die Dauer eines Zyklus */
    private $sock = null;
    private $tid = 0;
    /** Anzahl aufgebauter Verbindungen (Prüfstand/Diagnose). */
    public $connects = 0;
    /** Grund des letzten Fehlschlags: 'connect' | 'write' | 'eof' | 'timeout' | 'frame' | '' */
    public $lastError = '';

    public function close(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function connect(): bool
    {
        if ($this->sock) {
            return true;
        }
        $s = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($s === false) {
            $this->lastError = 'connect';
            return false;
        }
        stream_set_timeout($s, 3);
        $this->sock = $s;
        $this->connects++;
        return true;
    }

    /**
     * Anfrage senden, zugehörige Antwort-PDU (ab Funktionscode) liefern oder
     * null. Ein zweiter Versuch mit frischer Verbindung NUR, wenn eine schon
     * benutzte Verbindung weggebrochen ist (Gegenstelle hat sie geschlossen)
     * — nicht bei Zeitüberschreitung (ein stummes Gerät würde sonst doppelt
     * so lange blockieren) und nicht bei einer Modbus-Exception (die ist eine
     * gültige Antwort, die Verbindung bleibt nutzbar).
     */
    private function transact(string $pdu): ?string
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reused = $this->sock !== null;
            if (!$this->connect()) {
                return null;
            }
            $resp = $this->exchange($pdu);
            if ($resp !== null) {
                $this->lastError = '';
                return $resp;
            }
            $this->close();
            if (!$reused || !in_array($this->lastError, ['write', 'eof'], true)) {
                return null;
            }
        }
        return null;
    }

    private function exchange(string $pdu): ?string
    {
        $this->tid = ($this->tid % 65535) + 1;
        $tid = $this->tid;
        $frame = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId) . $pdu;
        if (@fwrite($this->sock, $frame) !== strlen($frame)) {
            $this->lastError = 'write';
            return null;
        }
        $deadline = microtime(true) + 3.0;
        while (true) {
            // MBAP: Transaktion(2) Protokoll(2) Länge(2) Unit(1); Länge zählt
            // Unit + PDU. Exakt so viel lesen — auf einer weiterbenutzten
            // Verbindung darf nichts von der nächsten Antwort verschluckt werden.
            $head = $this->readExact(7, $deadline);
            if ($head === null) {
                return null;
            }
            $h = unpack('ntid/npid/nlen', $head);
            if ($h['len'] < 2 || $h['len'] > 260) {
                $this->lastError = 'frame';
                return null;
            }
            $body = $this->readExact($h['len'] - 1, $deadline);
            if ($body === null) {
                return null;
            }
            if ($h['tid'] === $tid) {
                return $body;
            }
            // Verspätete Antwort einer früheren Anfrage — verwerfen, weiterlesen.
        }
    }

    private function readExact(int $n, float $deadline): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            if (microtime(true) >= $deadline) {
                $this->lastError = 'timeout';
                return null;
            }
            $chunk = @fread($this->sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = @stream_get_meta_data($this->sock);
                $this->lastError = !empty($meta['timed_out']) ? 'timeout' : 'eof';
                return null;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function modbusRead($fc, $startReg, $count)
    {
        $pdu = $this->transact(pack('Cnn', $fc, $startReg, $count));
        if ($pdu === null || strlen($pdu) < 2) {
            return null;
        }
        $rfc = ord($pdu[0]);
        if ($rfc & 0x80 || $rfc !== $fc) {
            return null;
        }

        $byteCount = ord($pdu[1]);
        $data      = substr($pdu, 2, $byteCount);

        $regs = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }

    public function u16($regs, $offset)
    {
        return isset($regs[$offset]) ? ($regs[$offset] & 0xFFFF) : 0;
    }

    public function u32($regs, $offset)
    {
        return (($this->u16($regs, $offset) << 16) | $this->u16($regs, $offset + 1));
    }

    public function s32($regs, $offset)
    {
        $v = $this->u32($regs, $offset);
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }

    // 32-Bit mit getauschter Wortreihenfolge (LSWMSW / CDAB — niederwertiges
    // Wort zuerst). Carlo Gavazzi u. a. legen ihre Doublewords so ab.
    public function u32sw($regs, $offset)
    {
        return (($this->u16($regs, $offset + 1) << 16) | $this->u16($regs, $offset));
    }

    public function s32sw($regs, $offset)
    {
        $v = $this->u32sw($regs, $offset);
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }

    // Wortreihenfolge tauschen (CDAB statt ABCD). Die meisten Zähler liefern
    // Float/Double big-endian (ABCD); einige Geräte/Gateways drehen die
    // 16-Bit-Wörter (z. B. Phoenix EEM-XM). Per Instanz-Schalter umstellbar.
    public $wordSwap = false;
    public function setWordSwap(bool $s) { $this->wordSwap = $s; }

    // IEEE-754 Float32 über 2 Register. Standard Big-Endian (ABCD); bei
    // gesetztem $wordSwap werden die beiden Wörter getauscht (CDAB).
    public function readFloat32($regs, $offset)
    {
        $w0 = $this->u16($regs, $offset);
        $w1 = $this->u16($regs, $offset + 1);
        if ($this->wordSwap) {
            $tmp = $w0; $w0 = $w1; $w1 = $tmp;
        }
        $raw = pack('nn', $w0, $w1);
        $val = unpack('G', $raw);
        return (float)($val[1] ?? 0.0);
    }

    // IEEE-754 Float64 (Double) über 4 Register, Big-Endian. Der PAC2200 legt
    // seine Energiezähler (Wirk-/Blindarbeit) als 64-Bit-Double ab. Bei
    // $wordSwap wird die 16-Bit-Wortreihenfolge komplett umgekehrt.
    public function readDouble64($regs, $offset)
    {
        $w = [
            $this->u16($regs, $offset),
            $this->u16($regs, $offset + 1),
            $this->u16($regs, $offset + 2),
            $this->u16($regs, $offset + 3),
        ];
        if ($this->wordSwap) {
            $w = array_reverse($w);
        }
        $raw = pack('nnnn', $w[0], $w[1], $w[2], $w[3]);
        $val = unpack('E', $raw);
        return (float)($val[1] ?? 0.0);
    }

    // Float32 -> 2 Register, Gegenstück zu readFloat32() für Schreibzugriffe
    // (blue'Log RPC/Power Control). Big-Endian (ABCD), bei $wordSwap
    // getauscht (CDAB) — dieselbe Konvention wie beim Lesen.
    public function packFloat32(float $value): array
    {
        $u = unpack('n2', pack('G', $value));
        $w0 = $u[1];
        $w1 = $u[2];
        return $this->wordSwap ? [$w1, $w0] : [$w0, $w1];
    }

    // Write Multiple Registers (FC 0x10) — bisher rein lesendes Modul, jetzt
    // erstmals ein Schreibzugriff (blue'Log RPC/Power Control Sollwerte).
    // Rückgabe: Modbus-Exception oder Zeitüberschreitung -> false.
    public function writeHolding($startReg, array $regs): bool
    {
        $count   = count($regs);
        $payload = '';
        foreach ($regs as $r) {
            $payload .= pack('n', $r & 0xFFFF);
        }
        // Über dieselbe Zyklus-Verbindung wie die Lesezugriffe. Ein erneutes
        // Senden nach weggebrochener Verbindung (transact()) ist bei FC16
        // unkritisch: derselbe Wert wird ein zweites Mal geschrieben.
        $pdu = $this->transact(pack('CnnC', 0x10, $startReg, $count, $count * 2) . $payload);
        if ($pdu === null || $pdu === '') {
            return false;
        }
        $rfc = ord($pdu[0]);
        return !($rfc & 0x80) && $rfc === 0x10;
    }
}

// ===========================================================================
// MHUB_ModbusGatewayClient — zweiter Verbindungsweg über Symcons natives
// Modbus-Gateway (eingebauter RS485-Port einer Symbox), zusätzlich zum
// bisherigen direkten fsockopen-Weg (MHUB_ModbusTcpClient), NICHT als Ersatz.
// Abgestimmte gemeinsame Fassade mit InverterHub/ChargerHub (SUITE.md 9j,
// Dietmars Auftrag 18.09.2026) — dieselbe öffentliche Schnittstelle wie
// MHUB_ModbusTcpClient (MHUB_ModbusClientInterface), damit GetModbusClient()
// zwischen beiden Wegen umschalten kann, ohne dass Treiber-Code sich
// unterscheiden muss.
//
// Nutzlastformat 18.09.2026 gegen den ROHEN Quellcode von Symcons eigenem
// Referenzmodul verifiziert (curl auf raw.githubusercontent.com/symcon/
// SymconBC/master/EM24-DIN/module.php — nicht nur eine KI-Zusammenfassung,
// EMS' Fund direkt gegengelesen): Parent-GUID {A5F663AB-C400-4FE5-B207-
// 4D67CC030564}, DataID {E310B701-4AE7-458E-B618-EC13A1A6F6A8}. Anfrage
// ["DataID"=>DataID, "Function"=>FC, "Address"=>Register, "Quantity"=>Anzahl,
// "Data"=>""], Antwort roh (kein JSON) — erste 2 Byte (Function+ByteCount)
// überspringen, Rest unpack('n*', ...) als big-endian 16-Bit-Register.
// Verbindung zum Gateway (seit 0.30.0): MeterHub selbst hat KEIN Elternteil —
// parentRequirements/implemented in dessen module.json hätten jede bestehende
// Direkt-Instanz mit dem Balken „benötigt eine übergeordnete Instanz" versehen
// (live bei InverterHub gesehen, 19.09.2026). Stattdessen hängt die Instanz
// MeterHubBridge unter dem Gateway (die trägt die Anforderung allein); MeterHub
// wählt sie im Feld BridgeInstanceID und ruft MHUBB_Forward() (siehe
// MeterHub::BridgeCall()). ConnectParent() bleibt ungenutzt: es würde laut
// SDK-Doku ungefragt einen Parent anlegen (InverterHubs Fund 18.09.2026).
//
// LESEND (Function 3/4) exakt nach diesem Vorbild — SCHREIBEND (Function 16)
// ist dagegen KEINE verifizierte Fundstelle: das Referenzmodul liest nur.
// "Data" folgt hier der naheliegenden Ableitung (gepackte Registerwerte statt
// der beim Lesen leeren ""), ist aber ungetestet, bis echte Symbox-Hardware
// das bestätigt — deutlich gekennzeichnet, kein Fatal Error, nur ein
// einmaliger Protokollhinweis zusätzlich zum normalen false-Rückgabewert.
// ===========================================================================
class MHUB_ModbusGatewayClient implements MHUB_ModbusClientInterface
{
    public const GATEWAY_GUID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
    private const DATA_ID = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';

    public $unitId;
    public $wordSwap = false;

    /** @var callable string(string $json) — i. d. R. $meterHubInstanz->SendDataToParent(...) */
    private $sendFn;

    private static $writeWarned = false;
    /** Hinweise für die Modulklasse (Store-Review 12.10.2026: Bibliotheksklassen haben kein $this->LogMessage) — abgeholt per takeNotices(). */
    private $notices = [];

    /** Liefert die seit dem letzten Abholen aufgelaufenen Hinweise und leert die Liste. */
    public function takeNotices(): array
    {
        $n = $this->notices;
        $this->notices = [];
        return $n;
    }

    public function __construct($unitId, callable $sendFn)
    {
        $this->unitId = $unitId;
        $this->sendFn = $sendFn;
    }

    /** Rohe Registerantwort (0x03/0x04) -> 0-indiziertes Array, wie modbusRead() es liefert. */
    private function request(int $function, int $address, int $quantity, string $data = ''): ?array
    {
        $json = json_encode([
            'DataID'   => self::DATA_ID,
            'Function' => $function,
            'Address'  => $address,
            'Quantity' => $quantity,
            'Data'     => $data,
        ]);
        // $data ist bei Schreibzugriffen roh gepackte Registerbyte (siehe
        // writeHolding()) — json_encode() scheitert (liefert false) an
        // vielen davon, da rohe Binärbytes selten gueltiges UTF-8 sind
        // (z. B. Registerwert 0xFFFF). Ohne diese Absicherung wuerde ein
        // false-Rueckgabewert als String "" an sendFn gehen: eine leere,
        // aber SYNTAKTISCH gueltige Anfrage statt eines erkennbaren
        // Fehlschlags — still falsch statt sauber abgelehnt.
        if ($json === false) {
            return null;
        }
        $resp = ($this->sendFn)($json);
        if ($resp === false || $resp === '' || strlen($resp) < 2) {
            return null;
        }
        // Erste 2 Byte (Function Code + Byte Count) überspringen, wie im
        // verifizierten Referenzmodul — unpack('n*', ...) liefert 1-indiziert.
        $raw = unpack('n*', substr($resp, 2));
        $regs = [];
        $i = 0;
        foreach ($raw as $v) {
            $regs[$i++] = $v;
        }
        return $regs;
    }

    public function readHolding($startReg, $count)
    {
        return $this->request(3, $startReg, $count);
    }

    public function readInput($startReg, $count)
    {
        return $this->request(4, $startReg, $count);
    }

    // Function 16 (Write Multiple) — ungetestete Ableitung, siehe Klassenkopf.
    public function writeHolding($startReg, array $regs): bool
    {
        if (!self::$writeWarned) {
            self::$writeWarned = true;
            $this->notices[] = MHUB_Text::t('Symbox gateway: write access (function 16) is an untested derivation from the verified read scheme, SUITE.md 9j — be sure to check on real hardware before a setpoint meter type goes into production over this connection path.');
        }
        $payload = '';
        foreach ($regs as $r) {
            $payload .= pack('n', $r & 0xFFFF);
        }
        // Base64, nicht der rohe Binaerstring direkt (der scheitert an
        // json_encode() fuer die meisten Registerwerte, siehe request()) —
        // selbst diese Kodierung ist Teil der ungetesteten Ableitung, aber
        // WENIGSTENS JSON-sicher; kein verifiziertes Beispiel dafuer
        // vorhanden (Referenzmodul schreibt nicht, siehe Klassenkopf).
        $resp = $this->request(16, $startReg, count($regs), base64_encode($payload));
        return $resp !== null;
    }

    public function close(): void
    {
    }

    public function setWordSwap(bool $s)
    {
        $this->wordSwap = $s;
    }

    // Register-Dekodierung — inhaltlich identisch zu MHUB_ModbusTcpClient,
    // bewusst dupliziert statt geteilt (reine, zustandslose Funktionen ohne
    // Bezug zum Verbindungsweg; dieselbe Konvention wie CompactionPlan()
    // zwischen MeterHub/MeterHubVirtual — kein Code-Sharing zwischen
    // IPS-Modulen möglich, hier zwischen zwei Klassen bewusst genauso
    // gehandhabt statt eines Traits, um MHUB_ModbusTcpClient unangetastet
    // zu lassen).
    public function u16($regs, $offset)
    {
        return isset($regs[$offset]) ? ($regs[$offset] & 0xFFFF) : 0;
    }

    public function u32($regs, $offset)
    {
        return (($this->u16($regs, $offset) << 16) | $this->u16($regs, $offset + 1));
    }

    public function s32($regs, $offset)
    {
        $v = $this->u32($regs, $offset);
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }

    public function u32sw($regs, $offset)
    {
        return (($this->u16($regs, $offset + 1) << 16) | $this->u16($regs, $offset));
    }

    public function s32sw($regs, $offset)
    {
        $v = $this->u32sw($regs, $offset);
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }

    public function readFloat32($regs, $offset)
    {
        $w0 = $this->u16($regs, $offset);
        $w1 = $this->u16($regs, $offset + 1);
        if ($this->wordSwap) {
            $tmp = $w0; $w0 = $w1; $w1 = $tmp;
        }
        $raw = pack('nn', $w0, $w1);
        $val = unpack('G', $raw);
        return (float)($val[1] ?? 0.0);
    }

    public function readDouble64($regs, $offset)
    {
        $w = [
            $this->u16($regs, $offset),
            $this->u16($regs, $offset + 1),
            $this->u16($regs, $offset + 2),
            $this->u16($regs, $offset + 3),
        ];
        if ($this->wordSwap) {
            $w = array_reverse($w);
        }
        $raw = pack('nnnn', $w[0], $w[1], $w[2], $w[3]);
        $val = unpack('E', $raw);
        return (float)($val[1] ?? 0.0);
    }

    public function packFloat32(float $value): array
    {
        $u = unpack('n2', pack('G', $value));
        $w0 = $u[1];
        $w1 = $u[2];
        return $this->wordSwap ? [$w1, $w0] : [$w0, $w1];
    }
}

// ---------------------------------------------------------------------------
// MHUB_MeterDriverInterface — Vertrag, den jeder Zähler-Treiber erfüllt
// ---------------------------------------------------------------------------

interface MHUB_MeterDriverInterface
{
    /**
     * Immer aktive Basisvariablen.
     * [ident, caption, type(F/I/B/S), profile, archive, group, reg-info]
     */
    public function getBaseVars();

    /**
     * Optionale Variablengruppen, je Property-Name (Checkbox in der Instanz).
     * ['GroupXYZ' => ['caption' => '...', 'vars' => [...]]]
     */
    public function getOptionalGroups();

    /** Custom-Profile, die nur dieser Treiber zusätzlich braucht. */
    public function getProfiles();

    /** Enum-Profile (Assoziationen): [name => [wert => [label, farbe]]] */
    public function getEnumProfiles();

    /** Liest die schnellen (Momentan-)Werte. Rückgabe: Verbindung erfolgreich? */
    public function readFast($mb, $hub);

    /** Liest die langsamen Werte (Energiezähler). */
    public function readSlow($mb, $hub);
}

// ---------------------------------------------------------------------------
// MHUB_CalculatedEnergyDriverInterface — optionaler Zusatzvertrag für Treiber,
// deren Gerät KEINEN Zählerstand liefert, aber eine verlässliche Leistung
// (bisher: blue'Log-Datenlogger, Adresse 97). MeterHub rechnet dann nach
// jedem erfolgreichen Lesezyklus Leistung × Zeit zu einem kumulativen
// Zählerstand hoch (AdvanceCalculatedEnergy()). Dietmars Auftrag 11.09.2026:
// „Wenn 97 kein Register für Energie liefert, dann müssen wir die Energie
// selbst ausrechnen lassen." Bewusst „hochgerechnet", nie „geschätzt" —
// eine Rechnung aus real gemessenen Werten (Dietmars Wortwahl 01.09.2026).
// ---------------------------------------------------------------------------

interface MHUB_CalculatedEnergyDriverInterface
{
    /** [Energie-Ident => Leistungs-Ident], z. B. ['energy_export' => 'power_total']. */
    public function calculatedEnergy(): array;
}

// ---------------------------------------------------------------------------
// MHUB_WritableMeterDriverInterface — optionaler Zusatzvertrag für Treiber,
// die auch SCHREIBEN (bisher nur die beiden blue'Log-Sollwertkanäle RPC/Power
// Control). Bewusst ein separates, optionales Interface statt einer
// Erweiterung von MHUB_MeterDriverInterface — sonst bräuchten alle 15
// bestehenden, rein lesenden Treiber eine leere Implementierung. MeterHub
// prüft per `instanceof`, ob der aktuell gewählte Treiber schreibt.
// ---------------------------------------------------------------------------

interface MHUB_WritableMeterDriverInterface
{
    /** Schreibt den aktuellen Zielwert (Property „TargetSetpoint") auf das Gerät. */
    public function writeSetpoint($mb, $hub): void;

    /** Schreibt den Ausfall-/Rückfallwert (Property „FailsafeDefaultPercent"), einmalig beim Deaktivieren. */
    public function writeFailsafe($mb, $hub): void;
}

// ---------------------------------------------------------------------------
// MHUB_Pac2200Driver — Siemens SENTRON PAC2200
// Float32-Messgrößen ab Register 1, Energiezähler als Double ab Register 801.
// Registeradressen laut Gerätehandbuch L1V30415167A (FC 0x03).
// ---------------------------------------------------------------------------

class MHUB_Pac2200Driver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power',       'F', 'NRG.Watt',   true,  'total',  'FC3 65 (Σ P)'],
            ['voltage_avg',   'Average voltage (L-N)',          'F', 'NRG.Volt',   false, 'total',  'FC3 57'],
            ['current_avg',   'Average current',                   'F', 'NRG.Ampere',   false, 'total',  'FC3 61'],
            ['frequency',     'Frequency',                  'F', 'MHB.Hz',  false, 'total',  'FC3 55'],
            ['energy_import', 'Active energy import (tariff 1)','F', 'NRG.kWh', true,  'energy', 'FC3 801 (Wh)'],
            ['energy_export', 'Active energy export (tariff 1)','F','NRG.kWh', true,  'energy', 'FC3 809 (Wh)'],
            ['connected',     'Connection',                'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase (L-N, L-L)', 'vars' => [
                ['u_l1_n',  'Voltage L1-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 1'],
                ['u_l2_n',  'Voltage L2-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 3'],
                ['u_l3_n',  'Voltage L3-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 5'],
                ['u_l1_l2', 'Voltage L1-L2', 'F', 'NRG.Volt', false, 'voltage', 'FC3 7'],
                ['u_l2_l3', 'Voltage L2-L3', 'F', 'NRG.Volt', false, 'voltage', 'FC3 9'],
                ['u_l3_l1', 'Voltage L3-L1', 'F', 'NRG.Volt', false, 'voltage', 'FC3 11'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase (+ neutral)', 'vars' => [
                ['i_l1', 'Current L1',           'F', 'NRG.Ampere', false, 'current', 'FC3 13'],
                ['i_l2', 'Current L2',           'F', 'NRG.Ampere', false, 'current', 'FC3 15'],
                ['i_l3', 'Current L3',           'F', 'NRG.Ampere', false, 'current', 'FC3 17'],
                ['i_n',  'Neutralleiterstrom', 'F', 'NRG.Ampere', false, 'current', 'FC3 71'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Active power per phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC3 25'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC3 27'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC3 29'],
            ]],
            'GroupReactiveApparent' => ['caption' => 'Reactive/apparent power (total + per phase)', 'vars' => [
                ['s_total', 'Total apparent power', 'F', 'MHB.VA',  false, 'power', 'FC3 63'],
                ['q_total', 'Total reactive power',  'F', 'MHB.var', false, 'power', 'FC3 67'],
                ['s_l1', 'Apparent power L1', 'F', 'MHB.VA',  false, 'power', 'FC3 19'],
                ['s_l2', 'Apparent power L2', 'F', 'MHB.VA',  false, 'power', 'FC3 21'],
                ['s_l3', 'Apparent power L3', 'F', 'MHB.VA',  false, 'power', 'FC3 23'],
                ['q_l1', 'Reactive power L1',  'F', 'MHB.var', false, 'power', 'FC3 31'],
                ['q_l2', 'Reactive power L2',  'F', 'MHB.var', false, 'power', 'FC3 33'],
                ['q_l3', 'Reactive power L3',  'F', 'MHB.var', false, 'power', 'FC3 35'],
            ]],
            'GroupPowerFactor' => ['caption' => 'Power factor', 'vars' => [
                ['pf_total', 'Total power factor', 'F', 'MHB.PF', false, 'total', 'FC3 69'],
                ['pf_l1', 'Power factor L1', 'F', 'MHB.PF', false, 'total', 'FC3 37'],
                ['pf_l2', 'Power factor L2', 'F', 'MHB.PF', false, 'total', 'FC3 39'],
                ['pf_l3', 'Power factor L3', 'F', 'MHB.PF', false, 'total', 'FC3 41'],
            ]],
            'GroupTariff2' => ['caption' => 'Energy tariff 2 (import/export)', 'vars' => [
                ['energy_import_t2', 'Active energy import (tariff 2)',  'F', 'NRG.kWh', true, 'energy', 'FC3 805 (Wh)'],
                ['energy_export_t2', 'Active energy export (tariff 2)', 'F', 'NRG.kWh', true, 'energy', 'FC3 813 (Wh)'],
            ]],
        ];
    }

    public function getProfiles()   { return []; }
    public function getEnumProfiles(){ return []; }

    // Momentanwerte: zwei lückenfreie Block-Reads. Zwischen Reg 41
    // (Leistungsfaktor L3) und Reg 55 (Frequenz) liegen im PAC2200
    // undefinierte Register — ein Block-Read darüber hinweg riskiert einen
    // „Illegal Data Address"-Fehler für die ganze Anfrage. Daher getrennt:
    //   Block A = Reg 1..42  (je Phase, Offset = Registeradresse − 1)
    //   Block B = Reg 55..72 (Summen/Mittelwerte, Offset = Registeradresse − 55)
    public function readFast($mb, $hub)
    {
        $a = $mb->readHolding(1, 42);
        $b = $mb->readHolding(55, 18);
        if ($a === null || $b === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);

        $hub->SetVarFloat('frequency',   $mb->readFloat32($b, 0));  // Reg 55
        $hub->SetVarFloat('voltage_avg', $mb->readFloat32($b, 2));  // Reg 57
        $hub->SetVarFloat('current_avg', $mb->readFloat32($b, 6));  // Reg 61
        $hub->SetVarFloat('power_total', $mb->readFloat32($b, 10)); // Reg 65

        if ($hub->GroupActive('GroupVoltagePhase')) {
            $hub->SetVarFloat('u_l1_n',  $mb->readFloat32($a, 0));   // Reg 1
            $hub->SetVarFloat('u_l2_n',  $mb->readFloat32($a, 2));
            $hub->SetVarFloat('u_l3_n',  $mb->readFloat32($a, 4));
            $hub->SetVarFloat('u_l1_l2', $mb->readFloat32($a, 6));
            $hub->SetVarFloat('u_l2_l3', $mb->readFloat32($a, 8));
            $hub->SetVarFloat('u_l3_l1', $mb->readFloat32($a, 10));
        }
        if ($hub->GroupActive('GroupCurrentPhase')) {
            $hub->SetVarFloat('i_l1', $mb->readFloat32($a, 12)); // Reg 13
            $hub->SetVarFloat('i_l2', $mb->readFloat32($a, 14));
            $hub->SetVarFloat('i_l3', $mb->readFloat32($a, 16));
            $hub->SetVarFloat('i_n',  $mb->readFloat32($b, 16)); // Reg 71 (Block B)
        }
        if ($hub->GroupActive('GroupPowerPhase')) {
            $hub->SetVarFloat('p_l1', $mb->readFloat32($a, 24)); // Reg 25
            $hub->SetVarFloat('p_l2', $mb->readFloat32($a, 26));
            $hub->SetVarFloat('p_l3', $mb->readFloat32($a, 28));
        }
        if ($hub->GroupActive('GroupReactiveApparent')) {
            $hub->SetVarFloat('s_total', $mb->readFloat32($b, 8));  // Reg 63 (Block B)
            $hub->SetVarFloat('q_total', $mb->readFloat32($b, 12)); // Reg 67 (Block B)
            $hub->SetVarFloat('s_l1', $mb->readFloat32($a, 18));    // Reg 19
            $hub->SetVarFloat('s_l2', $mb->readFloat32($a, 20));
            $hub->SetVarFloat('s_l3', $mb->readFloat32($a, 22));
            $hub->SetVarFloat('q_l1', $mb->readFloat32($a, 30));    // Reg 31
            $hub->SetVarFloat('q_l2', $mb->readFloat32($a, 32));
            $hub->SetVarFloat('q_l3', $mb->readFloat32($a, 34));
        }
        if ($hub->GroupActive('GroupPowerFactor')) {
            $hub->SetVarFloat('pf_total', $mb->readFloat32($b, 14)); // Reg 69 (Block B)
            $hub->SetVarFloat('pf_l1', $mb->readFloat32($a, 36));    // Reg 37
            $hub->SetVarFloat('pf_l2', $mb->readFloat32($a, 38));
            $hub->SetVarFloat('pf_l3', $mb->readFloat32($a, 40));
        }
        return true;
    }

    // Energiezähler: Double ab Register 801 (Bezug/Abgabe Tarif 1+2, in Wh).
    // Block-Read 801..816 (16 Register), Offset = Registeradresse − 801.
    public function readSlow($mb, $hub)
    {
        $r = $mb->readHolding(801, 16);
        if ($r === null) {
            return;
        }
        $hub->SetVarEnergyWh('energy_import', $mb->readDouble64($r, 0));  // Reg 801
        $hub->SetVarEnergyWh('energy_export', $mb->readDouble64($r, 8));  // Reg 809
        if ($hub->GroupActive('GroupTariff2')) {
            $hub->SetVarEnergyWh('energy_import_t2', $mb->readDouble64($r, 4));  // Reg 805
            $hub->SetVarEnergyWh('energy_export_t2', $mb->readDouble64($r, 12)); // Reg 813
        }
    }
}

// ---------------------------------------------------------------------------
// MHUB_JanitzaClassicDriver — klassische Janitza-UMG-Registerkarte (19000er-Block)
// Deckt UMG 604, 605-PRO, 509-PRO, 512-PRO, 806, 96PA und 801 ab — alle nutzen
// dieselbe feste Firmware-Karte: Float32-Messgrößen ab Register 19000, Energie
// als Float32 (Wh) bei 19068 (Bezug) / 19076 (Abgabe), Netzqualität (THD) ab
// 19110, Drehfeld 19052. FC 0x03. Ø-Spannung/-Strom werden aus den Phasen
// berechnet (statt aus dem optionalen 19630-Mittelwertblock, den nicht jedes
// Modell dieser Familie führt — z. B. der UMG 96PA nicht).
// ---------------------------------------------------------------------------

class MHUB_JanitzaClassicDriver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power',        'F', 'NRG.Watt',   true,  'total',  'FC3 19026 (Psum3)'],
            ['voltage_avg',   'Average voltage (L-N)',           'F', 'NRG.Volt',   false, 'total',  'FC3 19000/02/04 Ø'],
            ['current_avg',   'Average current',                    'F', 'NRG.Ampere',   false, 'total',  'FC3 19012/14/16 Ø'],
            ['frequency',     'Frequency',                   'F', 'MHB.Hz',  false, 'total',  'FC3 19050'],
            ['energy_import', 'Active energy import',           'F', 'NRG.kWh', true,  'energy', 'FC3 19068 (Wh)'],
            ['energy_export', 'Active energy export',          'F', 'NRG.kWh', true,  'energy', 'FC3 19076 (Wh)'],
            ['connected',     'Connection',                 'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase (L-N, L-L)', 'vars' => [
                ['u_l1_n',  'Voltage L1-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 19000'],
                ['u_l2_n',  'Voltage L2-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 19002'],
                ['u_l3_n',  'Voltage L3-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 19004'],
                ['u_l1_l2', 'Voltage L1-L2', 'F', 'NRG.Volt', false, 'voltage', 'FC3 19006'],
                ['u_l2_l3', 'Voltage L2-L3', 'F', 'NRG.Volt', false, 'voltage', 'FC3 19008'],
                ['u_l3_l1', 'Voltage L3-L1', 'F', 'NRG.Volt', false, 'voltage', 'FC3 19010'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase (+ total)', 'vars' => [
                ['i_l1', 'Current L1',       'F', 'NRG.Ampere', false, 'current', 'FC3 19012'],
                ['i_l2', 'Current L2',       'F', 'NRG.Ampere', false, 'current', 'FC3 19014'],
                ['i_l3', 'Current L3',       'F', 'NRG.Ampere', false, 'current', 'FC3 19016'],
                ['i_sum', 'Total current (I1+I2+I3)', 'F', 'NRG.Ampere', false, 'current', 'FC3 19018'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Active power per phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC3 19020'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC3 19022'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC3 19024'],
            ]],
            'GroupReactiveApparent' => ['caption' => 'Reactive/apparent power (total + per phase)', 'vars' => [
                ['s_total', 'Total apparent power', 'F', 'MHB.VA',  false, 'power', 'FC3 19034'],
                ['q_total', 'Total reactive power',  'F', 'MHB.var', false, 'power', 'FC3 19042'],
                ['s_l1', 'Apparent power L1', 'F', 'MHB.VA',  false, 'power', 'FC3 19028'],
                ['s_l2', 'Apparent power L2', 'F', 'MHB.VA',  false, 'power', 'FC3 19030'],
                ['s_l3', 'Apparent power L3', 'F', 'MHB.VA',  false, 'power', 'FC3 19032'],
                ['q_l1', 'Reactive power L1',  'F', 'MHB.var', false, 'power', 'FC3 19036'],
                ['q_l2', 'Reactive power L2',  'F', 'MHB.var', false, 'power', 'FC3 19038'],
                ['q_l3', 'Reactive power L3',  'F', 'MHB.var', false, 'power', 'FC3 19040'],
            ]],
            'GroupPowerFactor' => ['caption' => 'Power factor / cos φ', 'vars' => [
                ['pf_total', 'Total power factor', 'F', 'MHB.PF', false, 'total', 'FC3 19636'],
                ['pf_l1', 'cos φ L1', 'F', 'MHB.PF', false, 'total', 'FC3 19044'],
                ['pf_l2', 'cos φ L2', 'F', 'MHB.PF', false, 'total', 'FC3 19046'],
                ['pf_l3', 'cos φ L3', 'F', 'MHB.PF', false, 'total', 'FC3 19048'],
            ]],
            'GroupQuality' => ['caption' => 'Power quality (THD, rotating field)', 'vars' => [
                ['thd_u_l1', 'THD voltage L1', 'F', 'NRG.Percent', false, 'quality', 'FC3 19110'],
                ['thd_u_l2', 'THD voltage L2', 'F', 'NRG.Percent', false, 'quality', 'FC3 19112'],
                ['thd_u_l3', 'THD voltage L3', 'F', 'NRG.Percent', false, 'quality', 'FC3 19114'],
                ['thd_i_l1', 'THD current L1',    'F', 'NRG.Percent', false, 'quality', 'FC3 19116'],
                ['thd_i_l2', 'THD current L2',    'F', 'NRG.Percent', false, 'quality', 'FC3 19118'],
                ['thd_i_l3', 'THD current L3',    'F', 'NRG.Percent', false, 'quality', 'FC3 19120'],
                ['phase_seq', 'Rotating field', 'I', 'MHB.PhaseSeq', false, 'quality', 'FC3 19052'],
            ]],
        ];
    }

    public function getProfiles() { return []; }

    public function getEnumProfiles()
    {
        return [
            'MHB.PhaseSeq' => [
                -1 => ['Counter-clockwise rotating field', 0xFF8000],
                 0 => ['no rotating field',  0x808080],
                 1 => ['Clockwise rotating field', 0x00A000],
            ],
        ];
    }

    // Momentanwerte: mehrere Block-Reads (Messwerte, Mittelwerte, optional THD).
    public function readFast($mb, $hub)
    {
        // Messwertblock 19000..19053, Offset = Registeradresse − 19000.
        $r = $mb->readHolding(19000, 54);
        if ($r === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);

        $hub->SetVarFloat('power_total', $mb->readFloat32($r, 26)); // 19026
        $hub->SetVarFloat('frequency',   $mb->readFloat32($r, 50)); // 19050

        // Ø-Spannung/-Strom aus den Phasenwerten des Messblocks berechnen
        // (19000/02/04 bzw. 19012/14/16) — modellunabhängig verfügbar.
        $hub->SetVarFloat('voltage_avg',
            ($mb->readFloat32($r, 0) + $mb->readFloat32($r, 2) + $mb->readFloat32($r, 4)) / 3.0);
        $hub->SetVarFloat('current_avg',
            ($mb->readFloat32($r, 12) + $mb->readFloat32($r, 14) + $mb->readFloat32($r, 16)) / 3.0);

        if ($hub->GroupActive('GroupVoltagePhase')) {
            $hub->SetVarFloat('u_l1_n',  $mb->readFloat32($r, 0));   // 19000
            $hub->SetVarFloat('u_l2_n',  $mb->readFloat32($r, 2));
            $hub->SetVarFloat('u_l3_n',  $mb->readFloat32($r, 4));
            $hub->SetVarFloat('u_l1_l2', $mb->readFloat32($r, 6));
            $hub->SetVarFloat('u_l2_l3', $mb->readFloat32($r, 8));
            $hub->SetVarFloat('u_l3_l1', $mb->readFloat32($r, 10));
        }
        if ($hub->GroupActive('GroupCurrentPhase')) {
            $hub->SetVarFloat('i_l1',  $mb->readFloat32($r, 12)); // 19012
            $hub->SetVarFloat('i_l2',  $mb->readFloat32($r, 14));
            $hub->SetVarFloat('i_l3',  $mb->readFloat32($r, 16));
            $hub->SetVarFloat('i_sum', $mb->readFloat32($r, 18)); // 19018
        }
        if ($hub->GroupActive('GroupPowerPhase')) {
            $hub->SetVarFloat('p_l1', $mb->readFloat32($r, 20)); // 19020
            $hub->SetVarFloat('p_l2', $mb->readFloat32($r, 22));
            $hub->SetVarFloat('p_l3', $mb->readFloat32($r, 24));
        }
        if ($hub->GroupActive('GroupReactiveApparent')) {
            $hub->SetVarFloat('s_total', $mb->readFloat32($r, 34)); // 19034
            $hub->SetVarFloat('q_total', $mb->readFloat32($r, 42)); // 19042
            $hub->SetVarFloat('s_l1', $mb->readFloat32($r, 28));    // 19028
            $hub->SetVarFloat('s_l2', $mb->readFloat32($r, 30));
            $hub->SetVarFloat('s_l3', $mb->readFloat32($r, 32));
            $hub->SetVarFloat('q_l1', $mb->readFloat32($r, 36));    // 19036
            $hub->SetVarFloat('q_l2', $mb->readFloat32($r, 38));
            $hub->SetVarFloat('q_l3', $mb->readFloat32($r, 40));
        }
        if ($hub->GroupActive('GroupPowerFactor')) {
            $hub->SetVarFloat('pf_l1', $mb->readFloat32($r, 44)); // 19044
            $hub->SetVarFloat('pf_l2', $mb->readFloat32($r, 46));
            $hub->SetVarFloat('pf_l3', $mb->readFloat32($r, 48));
            // Gesamt-Leistungsfaktor liegt im Mittelwertblock (19636). Nicht
            // jedes Modell dieser Familie führt ihn — daher separat und robust
            // gegen Fehler (der UMG 96PA z. B. liefert ihn nicht).
            $pf = $mb->readHolding(19636, 2);
            if ($pf !== null) {
                $hub->SetVarFloat('pf_total', $mb->readFloat32($pf, 0)); // 19636
            }
        }
        if ($hub->GroupActive('GroupQuality')) {
            // Drehfeld: 1=rechts, 0=keins, -1=links (Float, auf Integer runden).
            $hub->SetVarInt('phase_seq', (int)round($mb->readFloat32($r, 52))); // 19052
        }

        // THD-Block 19110..19121 nur bei aktiver Netzqualitäts-Gruppe lesen.
        if ($hub->GroupActive('GroupQuality')) {
            $q = $mb->readHolding(19110, 12);
            if ($q !== null) {
                $hub->SetVarFloat('thd_u_l1', $mb->readFloat32($q, 0));  // 19110
                $hub->SetVarFloat('thd_u_l2', $mb->readFloat32($q, 2));
                $hub->SetVarFloat('thd_u_l3', $mb->readFloat32($q, 4));
                $hub->SetVarFloat('thd_i_l1', $mb->readFloat32($q, 6));  // 19116
                $hub->SetVarFloat('thd_i_l2', $mb->readFloat32($q, 8));
                $hub->SetVarFloat('thd_i_l3', $mb->readFloat32($q, 10));
            }
        }
        return true;
    }

    // Energie: Float32 in Wh. Bezug 19068, Abgabe 19076. Block-Read 19068..19077.
    public function readSlow($mb, $hub)
    {
        $r = $mb->readHolding(19068, 10);
        if ($r === null) {
            return;
        }
        $hub->SetVarEnergyWh('energy_import', $mb->readFloat32($r, 0)); // 19068
        $hub->SetVarEnergyWh('energy_export', $mb->readFloat32($r, 8)); // 19076
    }
}

// ---------------------------------------------------------------------------
// MHUB_Umg800Driver — Janitza UMG 800 (neue Generation)
// Der UMG 800 hat eine frei konfigurierbare Modbus-Registerkarte; dieser
// Treiber folgt der ausgelieferten Werks-Standardzuordnung (VirtualMeter
// „Group19"). Sie liegt zwar auch im 19000er-Bereich, ist aber ANDERS
// aufgebaut als die klassische Karte: Summen-Wirkleistung 19030, Frequenz
// 19054, Bezug 19072, Abgabe 19080; zwischen 19019 und 19024 liegt eine Lücke.
// Wurde die Modbus-Zuordnung im Gerät geändert, stimmen diese Adressen nicht.
// ---------------------------------------------------------------------------

class MHUB_Umg800Driver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power', 'F', 'NRG.Watt',   true,  'total',  'FC3 19030 (Σ P)'],
            ['voltage_avg',   'Average voltage (L-N)',    'F', 'NRG.Volt',   false, 'total',  'FC3 19000/02/04 Ø'],
            ['current_avg',   'Average current',             'F', 'NRG.Ampere',   false, 'total',  'FC3 19012/14/16 Ø'],
            ['frequency',     'Frequency',            'F', 'MHB.Hz',  false, 'total',  'FC3 19054'],
            ['energy_import', 'Active energy import',    'F', 'NRG.kWh', true,  'energy', 'FC3 19072 (Wh)'],
            ['energy_export', 'Active energy export',   'F', 'NRG.kWh', true,  'energy', 'FC3 19080 (Wh)'],
            ['connected',     'Connection',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase (L-N, L-L)', 'vars' => [
                ['u_l1_n',  'Voltage L1-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 19000'],
                ['u_l2_n',  'Voltage L2-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 19002'],
                ['u_l3_n',  'Voltage L3-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 19004'],
                ['u_l1_l2', 'Voltage L1-L2', 'F', 'NRG.Volt', false, 'voltage', 'FC3 19006'],
                ['u_l2_l3', 'Voltage L2-L3', 'F', 'NRG.Volt', false, 'voltage', 'FC3 19008'],
                ['u_l3_l1', 'Voltage L3-L1', 'F', 'NRG.Volt', false, 'voltage', 'FC3 19010'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase (+ I4)', 'vars' => [
                ['i_l1',  'Current L1', 'F', 'NRG.Ampere', false, 'current', 'FC3 19012'],
                ['i_l2',  'Current L2', 'F', 'NRG.Ampere', false, 'current', 'FC3 19014'],
                ['i_l3',  'Current L3', 'F', 'NRG.Ampere', false, 'current', 'FC3 19016'],
                ['i_sum', 'Current I4', 'F', 'NRG.Ampere', false, 'current', 'FC3 19018'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Active power per phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC3 19024'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC3 19026'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC3 19028'],
            ]],
            'GroupReactiveApparent' => ['caption' => 'Reactive/apparent power (total + per phase)', 'vars' => [
                ['s_total', 'Total apparent power', 'F', 'MHB.VA',  false, 'power', 'FC3 19038'],
                ['q_total', 'Total reactive power',  'F', 'MHB.var', false, 'power', 'FC3 19046'],
                ['s_l1', 'Apparent power L1', 'F', 'MHB.VA',  false, 'power', 'FC3 19032'],
                ['s_l2', 'Apparent power L2', 'F', 'MHB.VA',  false, 'power', 'FC3 19034'],
                ['s_l3', 'Apparent power L3', 'F', 'MHB.VA',  false, 'power', 'FC3 19036'],
                ['q_l1', 'Reactive power L1',  'F', 'MHB.var', false, 'power', 'FC3 19040'],
                ['q_l2', 'Reactive power L2',  'F', 'MHB.var', false, 'power', 'FC3 19042'],
                ['q_l3', 'Reactive power L3',  'F', 'MHB.var', false, 'power', 'FC3 19044'],
            ]],
            'GroupPowerFactor' => ['caption' => 'Power factor / cos φ', 'vars' => [
                ['pf_l1', 'cos φ L1', 'F', 'MHB.PF', false, 'total', 'FC3 19048'],
                ['pf_l2', 'cos φ L2', 'F', 'MHB.PF', false, 'total', 'FC3 19050'],
                ['pf_l3', 'cos φ L3', 'F', 'MHB.PF', false, 'total', 'FC3 19052'],
            ]],
            'GroupQuality' => ['caption' => 'Power quality (THD, rotating field)', 'vars' => [
                ['thd_u_l1', 'THD voltage L1', 'F', 'NRG.Percent', false, 'quality', 'FC3 19114'],
                ['thd_u_l2', 'THD voltage L2', 'F', 'NRG.Percent', false, 'quality', 'FC3 19116'],
                ['thd_u_l3', 'THD voltage L3', 'F', 'NRG.Percent', false, 'quality', 'FC3 19118'],
                ['thd_i_l1', 'THD current L1',    'F', 'NRG.Percent', false, 'quality', 'FC3 19120'],
                ['thd_i_l2', 'THD current L2',    'F', 'NRG.Percent', false, 'quality', 'FC3 19122'],
                ['thd_i_l3', 'THD current L3',    'F', 'NRG.Percent', false, 'quality', 'FC3 19124'],
                ['phase_seq', 'Rotating field', 'I', 'MHB.PhaseSeq', false, 'quality', 'FC3 19056'],
            ]],
        ];
    }

    public function getProfiles() { return []; }

    public function getEnumProfiles()
    {
        return [
            'MHB.PhaseSeq' => [
                -1 => ['Counter-clockwise rotating field', 0xFF8000],
                 0 => ['no rotating field',  0x808080],
                 1 => ['Clockwise rotating field', 0x00A000],
            ],
        ];
    }

    // Zwei lückenfreie Block-Reads: zwischen 19019 (I4) und 19024 (P1) liegen
    // im Werks-Mapping unbelegte Register.
    //   Block A = 19000..19019 (U L-N, U L-L, I1..I4), Offset = Adresse − 19000
    //   Block B = 19024..19057 (P/S/Q/PF, Freq, Drehfeld), Offset = Adresse − 19024
    public function readFast($mb, $hub)
    {
        $a = $mb->readHolding(19000, 20);
        $b = $mb->readHolding(19024, 34);
        if ($a === null || $b === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);

        $hub->SetVarFloat('power_total', $mb->readFloat32($b, 6));  // 19030
        $hub->SetVarFloat('frequency',   $mb->readFloat32($b, 30)); // 19054
        $hub->SetVarFloat('voltage_avg',
            ($mb->readFloat32($a, 0) + $mb->readFloat32($a, 2) + $mb->readFloat32($a, 4)) / 3.0);
        $hub->SetVarFloat('current_avg',
            ($mb->readFloat32($a, 12) + $mb->readFloat32($a, 14) + $mb->readFloat32($a, 16)) / 3.0);

        if ($hub->GroupActive('GroupVoltagePhase')) {
            $hub->SetVarFloat('u_l1_n',  $mb->readFloat32($a, 0));   // 19000
            $hub->SetVarFloat('u_l2_n',  $mb->readFloat32($a, 2));
            $hub->SetVarFloat('u_l3_n',  $mb->readFloat32($a, 4));
            $hub->SetVarFloat('u_l1_l2', $mb->readFloat32($a, 6));   // 19006
            $hub->SetVarFloat('u_l2_l3', $mb->readFloat32($a, 8));
            $hub->SetVarFloat('u_l3_l1', $mb->readFloat32($a, 10));
        }
        if ($hub->GroupActive('GroupCurrentPhase')) {
            $hub->SetVarFloat('i_l1',  $mb->readFloat32($a, 12)); // 19012
            $hub->SetVarFloat('i_l2',  $mb->readFloat32($a, 14));
            $hub->SetVarFloat('i_l3',  $mb->readFloat32($a, 16));
            $hub->SetVarFloat('i_sum', $mb->readFloat32($a, 18)); // 19018 (I4)
        }
        if ($hub->GroupActive('GroupPowerPhase')) {
            $hub->SetVarFloat('p_l1', $mb->readFloat32($b, 0)); // 19024
            $hub->SetVarFloat('p_l2', $mb->readFloat32($b, 2));
            $hub->SetVarFloat('p_l3', $mb->readFloat32($b, 4));
        }
        if ($hub->GroupActive('GroupReactiveApparent')) {
            $hub->SetVarFloat('s_total', $mb->readFloat32($b, 14)); // 19038
            $hub->SetVarFloat('q_total', $mb->readFloat32($b, 22)); // 19046
            $hub->SetVarFloat('s_l1', $mb->readFloat32($b, 8));     // 19032
            $hub->SetVarFloat('s_l2', $mb->readFloat32($b, 10));
            $hub->SetVarFloat('s_l3', $mb->readFloat32($b, 12));
            $hub->SetVarFloat('q_l1', $mb->readFloat32($b, 16));    // 19040
            $hub->SetVarFloat('q_l2', $mb->readFloat32($b, 18));
            $hub->SetVarFloat('q_l3', $mb->readFloat32($b, 20));
        }
        if ($hub->GroupActive('GroupPowerFactor')) {
            $hub->SetVarFloat('pf_l1', $mb->readFloat32($b, 24)); // 19048
            $hub->SetVarFloat('pf_l2', $mb->readFloat32($b, 26));
            $hub->SetVarFloat('pf_l3', $mb->readFloat32($b, 28));
        }
        if ($hub->GroupActive('GroupQuality')) {
            // RotationField ist beim UMG 800 ein (vorzeichenbehafteter) Integer.
            $hub->SetVarInt('phase_seq', $mb->s32($b, 32)); // 19056

            $q = $mb->readHolding(19114, 12);
            if ($q !== null) {
                $hub->SetVarFloat('thd_u_l1', $mb->readFloat32($q, 0));  // 19114
                $hub->SetVarFloat('thd_u_l2', $mb->readFloat32($q, 2));
                $hub->SetVarFloat('thd_u_l3', $mb->readFloat32($q, 4));
                $hub->SetVarFloat('thd_i_l1', $mb->readFloat32($q, 6));  // 19120
                $hub->SetVarFloat('thd_i_l2', $mb->readFloat32($q, 8));
                $hub->SetVarFloat('thd_i_l3', $mb->readFloat32($q, 10));
            }
        }
        return true;
    }

    // Energie: Float32 in Wh. Bezug-Summe 19072, Abgabe-Summe 19080.
    public function readSlow($mb, $hub)
    {
        $r = $mb->readHolding(19072, 10);
        if ($r === null) {
            return;
        }
        $hub->SetVarEnergyWh('energy_import', $mb->readFloat32($r, 0)); // 19072
        $hub->SetVarEnergyWh('energy_export', $mb->readFloat32($r, 8)); // 19080
    }
}

// ---------------------------------------------------------------------------
// MHUB_EastronSdmDriver — Eastron SDM72D-M v2
// FC 0x04 (Input Register), Float32 Big-Endian, Basisadresse 0. Energie in kWh.
// Registerkarte laut IP-Symcon-Forum-Vorlage / Eastron-Handbuch.
// ---------------------------------------------------------------------------

class MHUB_EastronSdmDriver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power', 'F', 'NRG.Watt',   true,  'total',  'FC4 52'],
            ['voltage_avg',   'Average voltage (L-N)',    'F', 'NRG.Volt',   false, 'total',  'FC4 42'],
            ['current_avg',   'Average current',             'F', 'NRG.Ampere',   false, 'total',  'FC4 46'],
            ['frequency',     'Frequency',            'F', 'MHB.Hz',  false, 'total',  'FC4 70'],
            ['energy_import', 'Active energy import',    'F', 'NRG.kWh', true,  'energy', 'FC4 72 (kWh)'],
            ['energy_export', 'Active energy export',   'F', 'NRG.kWh', true,  'energy', 'FC4 74 (kWh)'],
            ['connected',     'Connection',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase (L-N, L-L)', 'vars' => [
                ['u_l1_n',  'Voltage L1-N',  'F', 'NRG.Volt', false, 'voltage', 'FC4 0'],
                ['u_l2_n',  'Voltage L2-N',  'F', 'NRG.Volt', false, 'voltage', 'FC4 2'],
                ['u_l3_n',  'Voltage L3-N',  'F', 'NRG.Volt', false, 'voltage', 'FC4 4'],
                ['u_l1_l2', 'Voltage L1-L2', 'F', 'NRG.Volt', false, 'voltage', 'FC4 200'],
                ['u_l2_l3', 'Voltage L2-L3', 'F', 'NRG.Volt', false, 'voltage', 'FC4 202'],
                ['u_l3_l1', 'Voltage L3-L1', 'F', 'NRG.Volt', false, 'voltage', 'FC4 204'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase (+ neutral)', 'vars' => [
                ['i_l1', 'Current L1',           'F', 'NRG.Ampere', false, 'current', 'FC4 6'],
                ['i_l2', 'Current L2',           'F', 'NRG.Ampere', false, 'current', 'FC4 8'],
                ['i_l3', 'Current L3',           'F', 'NRG.Ampere', false, 'current', 'FC4 10'],
                ['i_n',  'Neutralleiterstrom', 'F', 'NRG.Ampere', false, 'current', 'FC4 224'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Active power per phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC4 12'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC4 14'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC4 16'],
            ]],
            'GroupReactiveApparent' => ['caption' => 'Reactive/apparent power (total + per phase)', 'vars' => [
                ['s_total', 'Total apparent power', 'F', 'MHB.VA',  false, 'power', 'FC4 56'],
                ['q_total', 'Total reactive power',  'F', 'MHB.var', false, 'power', 'FC4 60'],
                ['s_l1', 'Apparent power L1', 'F', 'MHB.VA',  false, 'power', 'FC4 18'],
                ['s_l2', 'Apparent power L2', 'F', 'MHB.VA',  false, 'power', 'FC4 20'],
                ['s_l3', 'Apparent power L3', 'F', 'MHB.VA',  false, 'power', 'FC4 22'],
                ['q_l1', 'Reactive power L1',  'F', 'MHB.var', false, 'power', 'FC4 24'],
                ['q_l2', 'Reactive power L2',  'F', 'MHB.var', false, 'power', 'FC4 26'],
                ['q_l3', 'Reactive power L3',  'F', 'MHB.var', false, 'power', 'FC4 28'],
            ]],
            'GroupPowerFactor' => ['caption' => 'Power factor', 'vars' => [
                ['pf_total', 'Total power factor', 'F', 'MHB.PF', false, 'total', 'FC4 62'],
                ['pf_l1', 'Power factor L1', 'F', 'MHB.PF', false, 'total', 'FC4 30'],
                ['pf_l2', 'Power factor L2', 'F', 'MHB.PF', false, 'total', 'FC4 32'],
                ['pf_l3', 'Power factor L3', 'F', 'MHB.PF', false, 'total', 'FC4 34'],
            ]],
        ];
    }

    public function getProfiles()    { return []; }
    public function getEnumProfiles(){ return []; }

    public function readFast($mb, $hub)
    {
        $a = $mb->readInput(0, 41);   // 0..40  (U/I/P/S/Q/PF je Phase)
        $b = $mb->readInput(42, 22);  // 42..63 (Ø + Summenwerte)
        if ($a === null || $b === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);

        $hub->SetVarFloat('power_total', $mb->readFloat32($b, 10)); // 52
        $hub->SetVarFloat('voltage_avg', $mb->readFloat32($b, 0));  // 42
        $hub->SetVarFloat('current_avg', $mb->readFloat32($b, 4));  // 46

        if ($hub->GroupActive('GroupVoltagePhase')) {
            $hub->SetVarFloat('u_l1_n', $mb->readFloat32($a, 0));
            $hub->SetVarFloat('u_l2_n', $mb->readFloat32($a, 2));
            $hub->SetVarFloat('u_l3_n', $mb->readFloat32($a, 4));
            $ll = $mb->readInput(200, 6); // 200..205 (L-L Spannungen)
            if ($ll !== null) {
                $hub->SetVarFloat('u_l1_l2', $mb->readFloat32($ll, 0));
                $hub->SetVarFloat('u_l2_l3', $mb->readFloat32($ll, 2));
                $hub->SetVarFloat('u_l3_l1', $mb->readFloat32($ll, 4));
            }
        }
        if ($hub->GroupActive('GroupCurrentPhase')) {
            $hub->SetVarFloat('i_l1', $mb->readFloat32($a, 6));
            $hub->SetVarFloat('i_l2', $mb->readFloat32($a, 8));
            $hub->SetVarFloat('i_l3', $mb->readFloat32($a, 10));
            $nn = $mb->readInput(224, 2); // 224 Neutralleiterstrom
            if ($nn !== null) {
                $hub->SetVarFloat('i_n', $mb->readFloat32($nn, 0));
            }
        }
        if ($hub->GroupActive('GroupPowerPhase')) {
            $hub->SetVarFloat('p_l1', $mb->readFloat32($a, 12));
            $hub->SetVarFloat('p_l2', $mb->readFloat32($a, 14));
            $hub->SetVarFloat('p_l3', $mb->readFloat32($a, 16));
        }
        if ($hub->GroupActive('GroupReactiveApparent')) {
            $hub->SetVarFloat('s_total', $mb->readFloat32($b, 14)); // 56
            $hub->SetVarFloat('q_total', $mb->readFloat32($b, 18)); // 60
            $hub->SetVarFloat('s_l1', $mb->readFloat32($a, 18));
            $hub->SetVarFloat('s_l2', $mb->readFloat32($a, 20));
            $hub->SetVarFloat('s_l3', $mb->readFloat32($a, 22));
            $hub->SetVarFloat('q_l1', $mb->readFloat32($a, 24));
            $hub->SetVarFloat('q_l2', $mb->readFloat32($a, 26));
            $hub->SetVarFloat('q_l3', $mb->readFloat32($a, 28));
        }
        if ($hub->GroupActive('GroupPowerFactor')) {
            $hub->SetVarFloat('pf_total', $mb->readFloat32($b, 20)); // 62
            $hub->SetVarFloat('pf_l1', $mb->readFloat32($a, 30));
            $hub->SetVarFloat('pf_l2', $mb->readFloat32($a, 32));
            $hub->SetVarFloat('pf_l3', $mb->readFloat32($a, 34));
        }

        $f = $mb->readInput(70, 2); // 70 Frequenz
        if ($f !== null) {
            $hub->SetVarFloat('frequency', $mb->readFloat32($f, 0));
        }
        return true;
    }

    public function readSlow($mb, $hub)
    {
        $r = $mb->readInput(72, 4); // 72 Bezug, 74 Abgabe (kWh)
        if ($r === null) {
            return;
        }
        $hub->SetVarEnergykWh('energy_import', $mb->readFloat32($r, 0));
        $hub->SetVarEnergykWh('energy_export', $mb->readFloat32($r, 2));
    }
}

// ---------------------------------------------------------------------------
// MHUB_EastronSdmSinglePhaseDriver — Eastron SDM120 / SDM220 / SDM230 (einphasig)
// FC 0x04 (Input Register), Float32 Big-Endian, Energie in kWh. Reine
// RS485-Geräte (Modbus RTU) — im Verbund über die Symbox-Brücke oder ein
// RTU/TCP-Gateway. Ab Werk Modbus-Adresse 1.
//
// Registerkarte (Wire-Adresse = Doku − 30001): Spannung 0, Strom 6, Wirkleistung
// 12, Scheinleistung 18, Blindleistung 24, Leistungsfaktor 30, Frequenz 70,
// Bezug 72, Abgabe 74. Gegen drei unabhängige Quellen gegengelesen: nmakel/
// sdm_modbus (SDM120, SDM230), evcc (SDM120, SDM220/230) und volkszaehler/mbmd
// (SDM120, SDM220, SDM230) — alle drei Geräte teilen dieselbe Karte. Anders als
// beim dreiphasigen SDM630 gibt es KEINE Summenregister (52 …): die Wirkleistung
// steht auf 12. Jede Größe wird einzeln gelesen (je 2 Register), wie in allen
// drei Quellen — die Register dazwischen sind nicht belegt, ein Blocklesen darüber
// riskiert „Illegal Data Address" für die ganze Anfrage.
// An echter Hardware bestätigt: ein Forum-Tester meldete am 20.09.2026, dass SDM120, SDM220 und
// SDM230 alle funktionieren.
// ---------------------------------------------------------------------------

class MHUB_EastronSdmSinglePhaseDriver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung',        'F', 'NRG.Watt',   true,  'total',  'FC4 12'],
            ['voltage_avg',   'Voltage (L-N)',      'F', 'NRG.Volt',   false, 'total',  'FC4 0'],
            ['current_avg',   'Current',               'F', 'NRG.Ampere', false, 'total',  'FC4 6'],
            ['frequency',     'Frequency',            'F', 'MHB.Hz',     false, 'total',  'FC4 70'],
            ['energy_import', 'Active energy import',    'F', 'NRG.kWh',    true,  'energy', 'FC4 72 (kWh)'],
            ['energy_export', 'Active energy export',   'F', 'NRG.kWh',    true,  'energy', 'FC4 74 (kWh)'],
            ['connected',     'Connection',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupReactiveApparent' => ['caption' => 'Reactive/apparent power', 'vars' => [
                ['s_total', 'Apparent power', 'F', 'MHB.VA',  false, 'power', 'FC4 18'],
                ['q_total', 'Reactive power',  'F', 'MHB.var', false, 'power', 'FC4 24'],
            ]],
            'GroupPowerFactor' => ['caption' => 'Power factor', 'vars' => [
                ['pf_total', 'Power factor', 'F', 'MHB.PF', false, 'total', 'FC4 30'],
            ]],
        ];
    }

    public function getProfiles()    { return []; }
    public function getEnumProfiles(){ return []; }

    public function readFast($mb, $hub)
    {
        $p = $mb->readInput(12, 2);
        if ($p === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);
        $hub->SetVarFloat('power_total', $mb->readFloat32($p, 0));

        foreach ([['voltage_avg', 0], ['current_avg', 6], ['frequency', 70]] as [$ident, $reg]) {
            $r = $mb->readInput($reg, 2);
            if ($r !== null) {
                $hub->SetVarFloat($ident, $mb->readFloat32($r, 0));
            }
        }
        if ($hub->GroupActive('GroupReactiveApparent')) {
            foreach ([['s_total', 18], ['q_total', 24]] as [$ident, $reg]) {
                $r = $mb->readInput($reg, 2);
                if ($r !== null) {
                    $hub->SetVarFloat($ident, $mb->readFloat32($r, 0));
                }
            }
        }
        if ($hub->GroupActive('GroupPowerFactor')) {
            $r = $mb->readInput(30, 2);
            if ($r !== null) {
                $hub->SetVarFloat('pf_total', $mb->readFloat32($r, 0));
            }
        }
        return true;
    }

    public function readSlow($mb, $hub)
    {
        foreach ([['energy_import', 72], ['energy_export', 74]] as [$ident, $reg]) {
            $r = $mb->readInput($reg, 2);
            if ($r !== null) {
                $hub->SetVarEnergykWh($ident, $mb->readFloat32($r, 0));
            }
        }
    }
}

// ---------------------------------------------------------------------------
// MHUB_WhatWattDriver — WhatWatt Smart Meter
// FC 0x04, Float32 (Momentanwerte, Energie-Summen) + Double (Tarif-Energie),
// Big-Endian. Wirkleistung getrennt als Bezug (501) und Abgabe (505) →
// Gesamt = Bezug − Abgabe. Keine Frequenz in der Vorlage.
// ---------------------------------------------------------------------------

class MHUB_WhatWattDriver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power', 'F', 'NRG.Watt',   true,  'total',  'FC4 501−505'],
            ['voltage_avg',   'Average voltage',          'F', 'NRG.Volt',   false, 'total',  'FC4 1/3/5 Ø'],
            ['current_avg',   'Average current',             'F', 'NRG.Ampere',   false, 'total',  'FC4 13/15/17 Ø'],
            ['energy_import', 'Active energy import',    'F', 'NRG.kWh', true,  'energy', 'FC4 549 (Wh)'],
            ['energy_export', 'Active energy export',   'F', 'NRG.kWh', true,  'energy', 'FC4 553 (Wh)'],
            ['connected',     'Connection',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase', 'vars' => [
                ['u_l1_n', 'Voltage L1', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1'],
                ['u_l2_n', 'Voltage L2', 'F', 'NRG.Volt', false, 'voltage', 'FC4 3'],
                ['u_l3_n', 'Voltage L3', 'F', 'NRG.Volt', false, 'voltage', 'FC4 5'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase', 'vars' => [
                ['i_l1', 'Current L1', 'F', 'NRG.Ampere', false, 'current', 'FC4 13'],
                ['i_l2', 'Current L2', 'F', 'NRG.Ampere', false, 'current', 'FC4 15'],
                ['i_l3', 'Current L3', 'F', 'NRG.Ampere', false, 'current', 'FC4 17'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Active power per phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC4 25'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC4 27'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC4 29'],
            ]],
            'GroupTariff2' => ['caption' => 'Energy by tariff (T1/T2)', 'vars' => [
                ['energy_import_t1', 'Import tariff 1',  'F', 'NRG.kWh', true, 'energy', 'FC4 801 (Wh)'],
                ['energy_import_t2', 'Import tariff 2',  'F', 'NRG.kWh', true, 'energy', 'FC4 805 (Wh)'],
                ['energy_export_t1', 'Export tariff 1', 'F', 'NRG.kWh', true, 'energy', 'FC4 809 (Wh)'],
                ['energy_export_t2', 'Export tariff 2', 'F', 'NRG.kWh', true, 'energy', 'FC4 813 (Wh)'],
            ]],
        ];
    }

    public function getProfiles()    { return []; }
    public function getEnumProfiles(){ return []; }

    public function readFast($mb, $hub)
    {
        $a = $mb->readInput(1, 30);   // 1..30 (U 1/3/5, I 13/15/17, P 25/27/29)
        $p = $mb->readInput(501, 8);  // 501 Bezug-Leistung, 505 Abgabe-Leistung
        if ($a === null || $p === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);

        $imp = $mb->readFloat32($p, 0); // 501
        $exp = $mb->readFloat32($p, 4); // 505
        $hub->SetVarFloat('power_total', $imp - $exp);
        $hub->SetVarFloat('voltage_avg',
            ($mb->readFloat32($a, 0) + $mb->readFloat32($a, 2) + $mb->readFloat32($a, 4)) / 3.0);
        $hub->SetVarFloat('current_avg',
            ($mb->readFloat32($a, 12) + $mb->readFloat32($a, 14) + $mb->readFloat32($a, 16)) / 3.0);

        if ($hub->GroupActive('GroupVoltagePhase')) {
            $hub->SetVarFloat('u_l1_n', $mb->readFloat32($a, 0));
            $hub->SetVarFloat('u_l2_n', $mb->readFloat32($a, 2));
            $hub->SetVarFloat('u_l3_n', $mb->readFloat32($a, 4));
        }
        if ($hub->GroupActive('GroupCurrentPhase')) {
            $hub->SetVarFloat('i_l1', $mb->readFloat32($a, 12));
            $hub->SetVarFloat('i_l2', $mb->readFloat32($a, 14));
            $hub->SetVarFloat('i_l3', $mb->readFloat32($a, 16));
        }
        if ($hub->GroupActive('GroupPowerPhase')) {
            $hub->SetVarFloat('p_l1', $mb->readFloat32($a, 24)); // 25
            $hub->SetVarFloat('p_l2', $mb->readFloat32($a, 26));
            $hub->SetVarFloat('p_l3', $mb->readFloat32($a, 28));
        }
        return true;
    }

    public function readSlow($mb, $hub)
    {
        $e = $mb->readInput(549, 6); // 549 Bezug, 553 Abgabe (Wh)
        if ($e !== null) {
            $hub->SetVarEnergyWh('energy_import', $mb->readFloat32($e, 0));
            $hub->SetVarEnergyWh('energy_export', $mb->readFloat32($e, 4));
        }
        if ($hub->GroupActive('GroupTariff2')) {
            $t = $mb->readInput(801, 16); // Doubles 801/805/809/813
            if ($t !== null) {
                $hub->SetVarEnergyWh('energy_import_t1', $mb->readDouble64($t, 0));
                $hub->SetVarEnergyWh('energy_import_t2', $mb->readDouble64($t, 4));
                $hub->SetVarEnergyWh('energy_export_t1', $mb->readDouble64($t, 8));
                $hub->SetVarEnergyWh('energy_export_t2', $mb->readDouble64($t, 12));
            }
        }
    }
}

// ---------------------------------------------------------------------------
// MHUB_PhoenixEem375Driver — Phoenix Contact EEM-EM375
// FC 0x04, Float32 Big-Endian, Basisadresse 4096. Nur Bezugsenergie (Wh).
// ---------------------------------------------------------------------------

class MHUB_PhoenixEem375Driver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power', 'F', 'NRG.Watt',   true,  'total',  'FC4 4134'],
            ['voltage_avg',   'Average voltage (L-N)',    'F', 'NRG.Volt',   false, 'total',  'FC4 4096/98/100 Ø'],
            ['current_avg',   'Average current',             'F', 'NRG.Ampere',   false, 'total',  'FC4 4110/12/14 Ø'],
            ['energy_import', 'Active energy import',    'F', 'NRG.kWh', true,  'energy', 'FC4 4358 (Wh)'],
            ['connected',     'Connection',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase (L-N)', 'vars' => [
                ['u_l1_n', 'Voltage L1-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 4096'],
                ['u_l2_n', 'Voltage L2-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 4098'],
                ['u_l3_n', 'Voltage L3-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 4100'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase', 'vars' => [
                ['i_l1', 'Current I1', 'F', 'NRG.Ampere', false, 'current', 'FC4 4110'],
                ['i_l2', 'Current I2', 'F', 'NRG.Ampere', false, 'current', 'FC4 4112'],
                ['i_l3', 'Current I3', 'F', 'NRG.Ampere', false, 'current', 'FC4 4114'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Active power per phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC4 4128'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC4 4130'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC4 4132'],
            ]],
        ];
    }

    public function getProfiles()    { return []; }
    public function getEnumProfiles(){ return []; }

    public function readFast($mb, $hub)
    {
        $a = $mb->readInput(4096, 40); // 4096..4135
        if ($a === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);

        $hub->SetVarFloat('power_total', $mb->readFloat32($a, 38)); // 4134
        $hub->SetVarFloat('voltage_avg',
            ($mb->readFloat32($a, 0) + $mb->readFloat32($a, 2) + $mb->readFloat32($a, 4)) / 3.0);
        $hub->SetVarFloat('current_avg',
            ($mb->readFloat32($a, 14) + $mb->readFloat32($a, 16) + $mb->readFloat32($a, 18)) / 3.0);

        if ($hub->GroupActive('GroupVoltagePhase')) {
            $hub->SetVarFloat('u_l1_n', $mb->readFloat32($a, 0));
            $hub->SetVarFloat('u_l2_n', $mb->readFloat32($a, 2));
            $hub->SetVarFloat('u_l3_n', $mb->readFloat32($a, 4));
        }
        if ($hub->GroupActive('GroupCurrentPhase')) {
            $hub->SetVarFloat('i_l1', $mb->readFloat32($a, 14));
            $hub->SetVarFloat('i_l2', $mb->readFloat32($a, 16));
            $hub->SetVarFloat('i_l3', $mb->readFloat32($a, 18));
        }
        if ($hub->GroupActive('GroupPowerPhase')) {
            $hub->SetVarFloat('p_l1', $mb->readFloat32($a, 32));
            $hub->SetVarFloat('p_l2', $mb->readFloat32($a, 34));
            $hub->SetVarFloat('p_l3', $mb->readFloat32($a, 36));
        }
        return true;
    }

    public function readSlow($mb, $hub)
    {
        $r = $mb->readInput(4358, 2); // Bezugsenergie (Wh)
        if ($r !== null) {
            $hub->SetVarEnergyWh('energy_import', $mb->readFloat32($r, 0));
        }
    }
}

// ---------------------------------------------------------------------------
// MHUB_PhoenixEemXmDriver — Phoenix Contact EEM-XM (xMxxx-Reihe)
// FC 0x04, Float32, Basisadresse 32774. Anordnung weicht vom EM375 ab; einige
// XM-Geräte liefern die Wörter getauscht — ggf. den WordSwap-Schalter nutzen.
// ---------------------------------------------------------------------------

class MHUB_PhoenixEemXmDriver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power', 'F', 'NRG.Watt',   true,  'total',  'FC4 32790'],
            ['voltage_avg',   'Average voltage (L-N)',    'F', 'NRG.Volt',   false, 'total',  'FC4 32774/76/78 Ø'],
            ['current_avg',   'Average current',             'F', 'NRG.Ampere',   false, 'total',  'FC4 32782/84/86 Ø'],
            ['energy_import', 'Active energy import',    'F', 'NRG.kWh', true,  'energy', 'FC4 37630 (Wh)'],
            ['connected',     'Connection',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase (L-N)', 'vars' => [
                ['u_l1_n', 'Voltage L1-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 32774'],
                ['u_l2_n', 'Voltage L2-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 32776'],
                ['u_l3_n', 'Voltage L3-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 32778'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase', 'vars' => [
                ['i_l1', 'Current I1', 'F', 'NRG.Ampere', false, 'current', 'FC4 32782'],
                ['i_l2', 'Current I2', 'F', 'NRG.Ampere', false, 'current', 'FC4 32784'],
                ['i_l3', 'Current I3', 'F', 'NRG.Ampere', false, 'current', 'FC4 32786'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Active power per phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC4 32798'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC4 32800'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC4 32802'],
            ]],
        ];
    }

    public function getProfiles()    { return []; }
    public function getEnumProfiles(){ return []; }

    public function readFast($mb, $hub)
    {
        $a = $mb->readInput(32774, 30); // 32774..32803
        if ($a === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);

        $hub->SetVarFloat('power_total', $mb->readFloat32($a, 16)); // 32790
        $hub->SetVarFloat('voltage_avg',
            ($mb->readFloat32($a, 0) + $mb->readFloat32($a, 2) + $mb->readFloat32($a, 4)) / 3.0);
        $hub->SetVarFloat('current_avg',
            ($mb->readFloat32($a, 8) + $mb->readFloat32($a, 10) + $mb->readFloat32($a, 12)) / 3.0);

        if ($hub->GroupActive('GroupVoltagePhase')) {
            $hub->SetVarFloat('u_l1_n', $mb->readFloat32($a, 0));
            $hub->SetVarFloat('u_l2_n', $mb->readFloat32($a, 2));
            $hub->SetVarFloat('u_l3_n', $mb->readFloat32($a, 4));
        }
        if ($hub->GroupActive('GroupCurrentPhase')) {
            $hub->SetVarFloat('i_l1', $mb->readFloat32($a, 8));
            $hub->SetVarFloat('i_l2', $mb->readFloat32($a, 10));
            $hub->SetVarFloat('i_l3', $mb->readFloat32($a, 12));
        }
        if ($hub->GroupActive('GroupPowerPhase')) {
            $hub->SetVarFloat('p_l1', $mb->readFloat32($a, 24)); // 32798
            $hub->SetVarFloat('p_l2', $mb->readFloat32($a, 26));
            $hub->SetVarFloat('p_l3', $mb->readFloat32($a, 28));
        }
        return true;
    }

    public function readSlow($mb, $hub)
    {
        $r = $mb->readInput(37630, 2); // Bezugsenergie (Wh)
        if ($r !== null) {
            $hub->SetVarEnergyWh('energy_import', $mb->readFloat32($r, 0));
        }
    }
}

// ---------------------------------------------------------------------------
// MHUB_CarloGavazziDriver — Carlo Gavazzi EM24 / EM300 / ET340
// FC 0x04, Int32 mit getauschter Wortreihenfolge (CDAB), Skalierung:
// U ×0,1 V · I ×0,001 A · P ×0,1 W · f ×0,1 Hz · Energie ×0,1 kWh.
// Registerkarte nach OpenEMS (io.openems.edge.meter.carlo.gavazzi.em300).
// ---------------------------------------------------------------------------

class MHUB_CarloGavazziDriver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power', 'F', 'NRG.Watt',   true,  'total',  'FC4 40 (×0,1)'],
            ['voltage_avg',   'Average voltage (L-N)',    'F', 'NRG.Volt',   false, 'total',  'FC4 0/2/4 Ø'],
            ['current_avg',   'Average current',             'F', 'NRG.Ampere',   false, 'total',  'FC4 12/14/16 Ø'],
            ['frequency',     'Frequency',            'F', 'MHB.Hz',  false, 'total',  'FC4 51 (×0,1)'],
            ['energy_import', 'Active energy import',    'F', 'NRG.kWh', true,  'energy', 'FC4 52 (×0,1 kWh)'],
            ['energy_export', 'Active energy export',   'F', 'NRG.kWh', true,  'energy', 'FC4 78 (×0,1 kWh)'],
            ['connected',     'Connection',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase (L-N)', 'vars' => [
                ['u_l1_n', 'Voltage L1-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 0'],
                ['u_l2_n', 'Voltage L2-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 2'],
                ['u_l3_n', 'Voltage L3-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 4'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase', 'vars' => [
                ['i_l1', 'Current L1', 'F', 'NRG.Ampere', false, 'current', 'FC4 12'],
                ['i_l2', 'Current L2', 'F', 'NRG.Ampere', false, 'current', 'FC4 14'],
                ['i_l3', 'Current L3', 'F', 'NRG.Ampere', false, 'current', 'FC4 16'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Active power per phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC4 18'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC4 20'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC4 22'],
            ]],
            'GroupReactiveApparent' => ['caption' => 'Reactive/apparent power (total + per phase)', 'vars' => [
                ['s_total', 'Total apparent power', 'F', 'MHB.VA',  false, 'power', 'FC4 42'],
                ['q_total', 'Total reactive power',  'F', 'MHB.var', false, 'power', 'FC4 44'],
                ['s_l1', 'Apparent power L1', 'F', 'MHB.VA',  false, 'power', 'FC4 24'],
                ['s_l2', 'Apparent power L2', 'F', 'MHB.VA',  false, 'power', 'FC4 26'],
                ['s_l3', 'Apparent power L3', 'F', 'MHB.VA',  false, 'power', 'FC4 28'],
                ['q_l1', 'Reactive power L1',  'F', 'MHB.var', false, 'power', 'FC4 30'],
                ['q_l2', 'Reactive power L2',  'F', 'MHB.var', false, 'power', 'FC4 32'],
                ['q_l3', 'Reactive power L3',  'F', 'MHB.var', false, 'power', 'FC4 34'],
            ]],
        ];
    }

    public function getProfiles()    { return []; }
    public function getEnumProfiles(){ return []; }

    public function readFast($mb, $hub)
    {
        $a = $mb->readInput(0, 46);   // 0..45 (U/I/P/S/Q je Phase + Summen)
        $b = $mb->readInput(51, 3);   // 51 Frequenz (u16)
        if ($a === null || $b === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);

        $hub->SetVarFloat('power_total', $mb->s32sw($a, 40) * 0.1); // 40
        $hub->SetVarFloat('voltage_avg',
            ($mb->s32sw($a, 0) + $mb->s32sw($a, 2) + $mb->s32sw($a, 4)) * 0.1 / 3.0);
        $hub->SetVarFloat('current_avg',
            ($mb->s32sw($a, 12) + $mb->s32sw($a, 14) + $mb->s32sw($a, 16)) * 0.001 / 3.0);
        $hub->SetVarFloat('frequency', $mb->u16($b, 0) * 0.1); // 51

        if ($hub->GroupActive('GroupVoltagePhase')) {
            $hub->SetVarFloat('u_l1_n', $mb->s32sw($a, 0) * 0.1);
            $hub->SetVarFloat('u_l2_n', $mb->s32sw($a, 2) * 0.1);
            $hub->SetVarFloat('u_l3_n', $mb->s32sw($a, 4) * 0.1);
        }
        if ($hub->GroupActive('GroupCurrentPhase')) {
            $hub->SetVarFloat('i_l1', $mb->s32sw($a, 12) * 0.001);
            $hub->SetVarFloat('i_l2', $mb->s32sw($a, 14) * 0.001);
            $hub->SetVarFloat('i_l3', $mb->s32sw($a, 16) * 0.001);
        }
        if ($hub->GroupActive('GroupPowerPhase')) {
            $hub->SetVarFloat('p_l1', $mb->s32sw($a, 18) * 0.1);
            $hub->SetVarFloat('p_l2', $mb->s32sw($a, 20) * 0.1);
            $hub->SetVarFloat('p_l3', $mb->s32sw($a, 22) * 0.1);
        }
        if ($hub->GroupActive('GroupReactiveApparent')) {
            $hub->SetVarFloat('s_total', $mb->s32sw($a, 42) * 0.1);
            $hub->SetVarFloat('q_total', $mb->s32sw($a, 44) * 0.1);
            $hub->SetVarFloat('s_l1', $mb->s32sw($a, 24) * 0.1);
            $hub->SetVarFloat('s_l2', $mb->s32sw($a, 26) * 0.1);
            $hub->SetVarFloat('s_l3', $mb->s32sw($a, 28) * 0.1);
            $hub->SetVarFloat('q_l1', $mb->s32sw($a, 30) * 0.1);
            $hub->SetVarFloat('q_l2', $mb->s32sw($a, 32) * 0.1);
            $hub->SetVarFloat('q_l3', $mb->s32sw($a, 34) * 0.1);
        }
        return true;
    }

    public function readSlow($mb, $hub)
    {
        $r = $mb->readInput(52, 28); // 52 Bezug, 78 Abgabe (×0,1 kWh)
        if ($r === null) {
            return;
        }
        $hub->SetVarEnergykWh('energy_import', $mb->s32sw($r, 0)  * 0.1); // 52
        $hub->SetVarEnergykWh('energy_export', $mb->s32sw($r, 26) * 0.1); // 78
    }
}

// ---------------------------------------------------------------------------
// MHUB_SocomecCountisDriver — Socomec Countis E23/E24/E27/E28/E34/E44 (EXPERIMENTELL)
// FC 0x03, Big-Endian; U als UInt32 ×0,01 V, I UInt32 ×0,001 A, f UInt32
// ×0,001 Hz, P/Q Int32 ×10 W/var, Energie UInt32 ×0,01 kWh. Skalen nach
// OpenEMS abgeleitet — an echtem Gerät prüfen (v. a. Leistungs-Skala).
//
// Gegenrecherche 27.07.2026 (offizielle Socomec-Countis-E23-Kommunikations-
// tabelle über eine Drittquelle, da socomec.fr/us automatisierte Abrufe
// blockt): U_L1/L2/L3, I_L1/L2/L3, Frequenz und Wirkleistung gesamt
// (0xC558–0xC568, sieben unabhängig geprüfte Register) stimmen exakt —
// starkes Indiz, dass dieser Block korrekt ist. Der Energiezähler
// (0xC702/0xC708 hier) weicht von der Drittquelle ab (dort „ea+" bei
// 50770 = 0xC652, ~176 Register Differenz) — die Quelle war aber selbst
// unvollständig („mehr Register, Vollversion nötig"), könnte also ein
// anderes Energiefeld (Tarif/rücksetzbar) meinen. Nicht blind übernommen
// (Lehre aus dem Shelly-Pro-3EM-Vorfall, siehe CLAUDE.md „Registerkarten:
// erst messen, dann glauben") — bleibt offen für eine echte Gegenprobe an
// Hardware oder der vollständigen Herstellertabelle.
// ---------------------------------------------------------------------------

class MHUB_SocomecCountisDriver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power', 'F', 'NRG.Watt',   true,  'total',  'FC3 0xC568 (×10)'],
            ['voltage_avg',   'Average voltage (L-N)',    'F', 'NRG.Volt',   false, 'total',  'FC3 0xC558/5A/5C Ø'],
            ['current_avg',   'Average current',             'F', 'NRG.Ampere',   false, 'total',  'FC3 0xC560/62/64 Ø'],
            ['frequency',     'Frequency',            'F', 'MHB.Hz',  false, 'total',  'FC3 0xC55E'],
            ['energy_import', 'Active energy import',    'F', 'NRG.kWh', true,  'energy', 'FC3 0xC702 (×0,01)'],
            ['energy_export', 'Active energy export',   'F', 'NRG.kWh', true,  'energy', 'FC3 0xC708 (×0,01)'],
            ['connected',     'Connection',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase (L-N)', 'vars' => [
                ['u_l1_n', 'Voltage L1-N', 'F', 'NRG.Volt', false, 'voltage', 'FC3 0xC558'],
                ['u_l2_n', 'Voltage L2-N', 'F', 'NRG.Volt', false, 'voltage', 'FC3 0xC55A'],
                ['u_l3_n', 'Voltage L3-N', 'F', 'NRG.Volt', false, 'voltage', 'FC3 0xC55C'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase', 'vars' => [
                ['i_l1', 'Current L1', 'F', 'NRG.Ampere', false, 'current', 'FC3 0xC560'],
                ['i_l2', 'Current L2', 'F', 'NRG.Ampere', false, 'current', 'FC3 0xC562'],
                ['i_l3', 'Current L3', 'F', 'NRG.Ampere', false, 'current', 'FC3 0xC564'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Active power per phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC3 0xC570'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC3 0xC572'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC3 0xC574'],
            ]],
        ];
    }

    public function getProfiles()    { return []; }
    public function getEnumProfiles(){ return []; }

    public function readFast($mb, $hub)
    {
        // Messblock 0xC558..0xC57B (Offset = Adresse − 0xC558).
        $a = $mb->readHolding(0xC558, 36);
        if ($a === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);

        $hub->SetVarFloat('power_total', $mb->s32($a, 16) * 10.0);       // 0xC568
        $hub->SetVarFloat('voltage_avg',
            ($mb->u32($a, 0) + $mb->u32($a, 2) + $mb->u32($a, 4)) * 0.01 / 3.0);
        $hub->SetVarFloat('current_avg',
            ($mb->u32($a, 8) + $mb->u32($a, 10) + $mb->u32($a, 12)) * 0.001 / 3.0);
        $hub->SetVarFloat('frequency', $mb->u32($a, 6) * 0.001);          // 0xC55E

        if ($hub->GroupActive('GroupVoltagePhase')) {
            $hub->SetVarFloat('u_l1_n', $mb->u32($a, 0) * 0.01);
            $hub->SetVarFloat('u_l2_n', $mb->u32($a, 2) * 0.01);
            $hub->SetVarFloat('u_l3_n', $mb->u32($a, 4) * 0.01);
        }
        if ($hub->GroupActive('GroupCurrentPhase')) {
            $hub->SetVarFloat('i_l1', $mb->u32($a, 8)  * 0.001);
            $hub->SetVarFloat('i_l2', $mb->u32($a, 10) * 0.001);
            $hub->SetVarFloat('i_l3', $mb->u32($a, 12) * 0.001);
        }
        if ($hub->GroupActive('GroupPowerPhase')) {
            $hub->SetVarFloat('p_l1', $mb->s32($a, 24) * 10.0); // 0xC570
            $hub->SetVarFloat('p_l2', $mb->s32($a, 26) * 10.0);
            $hub->SetVarFloat('p_l3', $mb->s32($a, 28) * 10.0);
        }
        return true;
    }

    public function readSlow($mb, $hub)
    {
        $r = $mb->readHolding(0xC702, 8); // 0xC702 Bezug, 0xC708 Abgabe
        if ($r === null) {
            return;
        }
        $hub->SetVarEnergykWh('energy_import', $mb->u32($r, 0) * 0.01); // 0xC702
        $hub->SetVarEnergykWh('energy_export', $mb->u32($r, 6) * 0.01); // 0xC708
    }
}

// ---------------------------------------------------------------------------
// MHUB_MbsProfessionalDriver — MBS Professional 3-75 M-Bus/Modbus-Gateway (EXPERIMENTELL)
// FC 0x03, Big-Endian. Aus den IP-Symcon-Forum-Vorlagen abgeleitet: Bezug/
// Abgabe als UInt32 ×0,001 kWh, Wirkleistung Int32 (W), Spannung/Frequenz
// UInt16 ×0,1. Integer-Typgrößen aus den Vorlagen abgeleitet — an echtem
// Gateway prüfen.
// ---------------------------------------------------------------------------

class MHUB_MbsProfessionalDriver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power', 'F', 'NRG.Watt',   true,  'total',  'FC3 4527'],
            ['voltage_avg',   'Average voltage',          'F', 'NRG.Volt',   false, 'total',  'FC3 4567/68/69 Ø'],
            ['frequency',     'Frequency',            'F', 'MHB.Hz',  false, 'total',  'FC3 4626 (×0,1)'],
            ['energy_import', 'Active energy import',    'F', 'NRG.kWh', true,  'energy', 'FC3 4201 (×0,001)'],
            ['energy_export', 'Active energy export',   'F', 'NRG.kWh', true,  'energy', 'FC3 4281 (×0,001)'],
            ['connected',     'Connection',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase', 'vars' => [
                ['u_l1_n', 'Voltage L1', 'F', 'NRG.Volt', false, 'voltage', 'FC3 4567 (×0,1)'],
                ['u_l2_n', 'Voltage L2', 'F', 'NRG.Volt', false, 'voltage', 'FC3 4568 (×0,1)'],
                ['u_l3_n', 'Voltage L3', 'F', 'NRG.Volt', false, 'voltage', 'FC3 4569 (×0,1)'],
            ]],
        ];
    }

    public function getProfiles()    { return []; }
    public function getEnumProfiles(){ return []; }

    public function readFast($mb, $hub)
    {
        $p = $mb->readHolding(4527, 2); // Wirkleistung Int32
        $v = $mb->readHolding(4567, 3); // Spannung L1/L2/L3 UInt16
        $f = $mb->readHolding(4626, 1); // Frequenz UInt16
        if ($p === null || $v === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);

        $hub->SetVarFloat('power_total', (float)$mb->s32($p, 0));
        $hub->SetVarFloat('voltage_avg',
            ($mb->u16($v, 0) + $mb->u16($v, 1) + $mb->u16($v, 2)) * 0.1 / 3.0);
        if ($f !== null) {
            $hub->SetVarFloat('frequency', $mb->u16($f, 0) * 0.1);
        }
        if ($hub->GroupActive('GroupVoltagePhase')) {
            $hub->SetVarFloat('u_l1_n', $mb->u16($v, 0) * 0.1);
            $hub->SetVarFloat('u_l2_n', $mb->u16($v, 1) * 0.1);
            $hub->SetVarFloat('u_l3_n', $mb->u16($v, 2) * 0.1);
        }
        return true;
    }

    public function readSlow($mb, $hub)
    {
        $imp = $mb->readHolding(4201, 2); // Bezug UInt32
        $exp = $mb->readHolding(4281, 2); // Abgabe UInt32
        if ($imp !== null) {
            $hub->SetVarEnergykWh('energy_import', $mb->u32($imp, 0) * 0.001);
        }
        if ($exp !== null) {
            $hub->SetVarEnergykWh('energy_export', $mb->u32($exp, 0) * 0.001);
        }
    }
}

// ---------------------------------------------------------------------------
// MHUB_ShellyPro3emDriver — Shelly Pro 3EM
// FC 0x04 (Input Register), Float32 mit GETAUSCHTER Wortreihenfolge (CDAB).
// Wire-Adresse = Doku-Registernummer − 30000: EM-Messwerte ab 1011
// (Gesamtleistung 1013, Frequenz 1033, Phasen 1020/40/60 …), EMData-Energie
// 1162 (Bezug) / 1164 (Einspeisung) in Wh. An echtem Shelly Pro 3EM verifiziert.
// Modbus TCP muss am Gerät aktiviert sein (Einstellungen → Modbus, tcp/502).
// ---------------------------------------------------------------------------

class MHUB_ShellyPro3emDriver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power', 'F', 'NRG.Watt',   true,  'total',  'FC4 1013'],
            ['voltage_avg',   'Average voltage',          'F', 'NRG.Volt',   false, 'total',  'FC4 1020/40/60 Ø'],
            ['current_avg',   'Average current',             'F', 'NRG.Ampere',   false, 'total',  'FC4 1022/42/62 Ø'],
            ['frequency',     'Frequency',            'F', 'MHB.Hz',  false, 'total',  'FC4 1033'],
            ['energy_import', 'Active energy import',    'F', 'NRG.kWh', true,  'energy', 'FC4 1162 (Wh)'],
            ['energy_export', 'Active energy export',   'F', 'NRG.kWh', true,  'energy', 'FC4 1164 (Wh)'],
            ['connected',     'Connection',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase', 'vars' => [
                ['u_l1_n', 'Voltage L1', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1020'],
                ['u_l2_n', 'Voltage L2', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1040'],
                ['u_l3_n', 'Voltage L3', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1060'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase', 'vars' => [
                ['i_l1', 'Current L1', 'F', 'NRG.Ampere', false, 'current', 'FC4 1022'],
                ['i_l2', 'Current L2', 'F', 'NRG.Ampere', false, 'current', 'FC4 1042'],
                ['i_l3', 'Current L3', 'F', 'NRG.Ampere', false, 'current', 'FC4 1062'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Active power per phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC4 1024'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC4 1044'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC4 1064'],
            ]],
            // Eigene Energiezähler je Phase — damit lässt sich jede Phase als
            // eigenständiger Verbraucher führen (Summe der drei = Gesamtzähler).
            'GroupEnergyPhase' => ['caption' => 'Energy per phase (import/export)', 'vars' => [
                ['energy_import_l1', 'Active energy import L1',  'F', 'NRG.kWh', true, 'energy', 'FC4 1182 (Wh)'],
                ['energy_export_l1', 'Active energy export L1', 'F', 'NRG.kWh', true, 'energy', 'FC4 1184 (Wh)'],
                ['energy_import_l2', 'Active energy import L2',  'F', 'NRG.kWh', true, 'energy', 'FC4 1202 (Wh)'],
                ['energy_export_l2', 'Active energy export L2', 'F', 'NRG.kWh', true, 'energy', 'FC4 1204 (Wh)'],
                ['energy_import_l3', 'Active energy import L3',  'F', 'NRG.kWh', true, 'energy', 'FC4 1222 (Wh)'],
                ['energy_export_l3', 'Active energy export L3', 'F', 'NRG.kWh', true, 'energy', 'FC4 1224 (Wh)'],
            ]],
        ];
    }

    public function getProfiles()    { return []; }
    public function getEnumProfiles(){ return []; }

    public function readFast($mb, $hub)
    {
        // Shelly liefert Float32 wortgetauscht (CDAB) — für diesen Treiber fest.
        $mb->setWordSwap(true);
        $a = $mb->readInput(1011, 64); // 1011..1074 (Offset = Adresse − 1011)
        if ($a === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);

        $hub->SetVarFloat('power_total', $mb->readFloat32($a, 2));  // 1013
        $hub->SetVarFloat('frequency',   $mb->readFloat32($a, 22)); // 1033
        $hub->SetVarFloat('voltage_avg',
            ($mb->readFloat32($a, 9) + $mb->readFloat32($a, 29) + $mb->readFloat32($a, 49)) / 3.0);
        $hub->SetVarFloat('current_avg',
            ($mb->readFloat32($a, 11) + $mb->readFloat32($a, 31) + $mb->readFloat32($a, 51)) / 3.0);

        if ($hub->GroupActive('GroupVoltagePhase')) {
            $hub->SetVarFloat('u_l1_n', $mb->readFloat32($a, 9));  // 1020
            $hub->SetVarFloat('u_l2_n', $mb->readFloat32($a, 29)); // 1040
            $hub->SetVarFloat('u_l3_n', $mb->readFloat32($a, 49)); // 1060
        }
        if ($hub->GroupActive('GroupCurrentPhase')) {
            $hub->SetVarFloat('i_l1', $mb->readFloat32($a, 11)); // 1022
            $hub->SetVarFloat('i_l2', $mb->readFloat32($a, 31)); // 1042
            $hub->SetVarFloat('i_l3', $mb->readFloat32($a, 51)); // 1062
        }
        if ($hub->GroupActive('GroupPowerPhase')) {
            $hub->SetVarFloat('p_l1', $mb->readFloat32($a, 13)); // 1024
            $hub->SetVarFloat('p_l2', $mb->readFloat32($a, 33)); // 1044
            $hub->SetVarFloat('p_l3', $mb->readFloat32($a, 53)); // 1064
        }
        return true;
    }

    public function readSlow($mb, $hub)
    {
        $mb->setWordSwap(true);
        // EMData in EINEM Block: 1162..1225. Summe bei 1162/1164, danach je
        // Phase im Abstand von 20 Registern (L1 1182/1184, L2 1202/1204,
        // L3 1222/1224). Alle Werte Float wortgetauscht in Wh.
        // (Am Shelly Pro 3EM Gen3 verifiziert: L1+L2+L3 = Gesamtzähler.)
        $e = $mb->readInput(1162, 64);
        if ($e === null) {
            return;
        }
        $hub->SetVarEnergyWh('energy_import', $mb->readFloat32($e, 0)); // 1162
        $hub->SetVarEnergyWh('energy_export', $mb->readFloat32($e, 2)); // 1164

        if ($hub->GroupActive('GroupEnergyPhase')) {
            $hub->SetVarEnergyWh('energy_import_l1', $mb->readFloat32($e, 20)); // 1182
            $hub->SetVarEnergyWh('energy_export_l1', $mb->readFloat32($e, 22)); // 1184
            $hub->SetVarEnergyWh('energy_import_l2', $mb->readFloat32($e, 40)); // 1202
            $hub->SetVarEnergyWh('energy_export_l2', $mb->readFloat32($e, 42)); // 1204
            $hub->SetVarEnergyWh('energy_import_l3', $mb->readFloat32($e, 60)); // 1222
            $hub->SetVarEnergyWh('energy_export_l3', $mb->readFloat32($e, 62)); // 1224
        }
    }
}

// ---------------------------------------------------------------------------
// go-e Controller — Energiemess-Zentrale (kein Ladegerät; die Wallboxen des
// Herstellers bedient ChargerHub). Übernommen von dort am 22.07.2026.
//
// Quelle: github.com/goecharger/go-eController-API (modbus-de.md), an einem
// echten Controller verifiziert. FC 0x04 (Input), Float32/Float64 Big-Endian
// (ABCD), Wire-Adresse = Doku − 30001. Modbus TCP muss am Gerät erst
// aktiviert werden (App: Internet → Erweiterte Einstellungen → Modbus, oder
// HTTP-API men=true) — sonst bleibt Port 502 GESCHLOSSEN; nach dem Aktivieren
// ggf. die Einstellung einmal aus-/einschalten oder das Gerät neu starten.
//
// Registerbild (live bestätigt):
//   1000..1006  Spannung L1/L2/L3/N (Float32) — 1008 „Frequenz" ist NICHT
//               implementiert und liefert 0xFFFFFFFF (NaN)
//   1010..1045  Sensoren 1-6: Strom, Leistung, Leistungsfaktor (je Float32)
//   ab 1046     Kategorie-Blöcke im 26-Register-Raster: Home 1046, Grid 1072,
//               Car 1098, Relais 1124, Solar 1150, Akku 1176. Je Block:
//               +0..7 Ströme L1/L2/L3/N (F32), +8 Leistung (F32),
//               +10 Energie Ein (F64, Wh), +14 Energie Aus (F64, Wh),
//               +18..25 „Money" — nicht implementiert, wird übersprungen.
//
// Unbelegte Register beantwortet das Gerät mit 0xFFFF… (NaN) statt einer
// Modbus-Exception — Werte daher vor der Übernahme auf is_finite prüfen.
// Vorzeichen: am Gerät bestätigt − = Einspeisung, passt zur Konvention.
// ---------------------------------------------------------------------------

class MHUB_GoeControllerDriver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power (Grid)', 'F', 'NRG.Watt',   true,  'total',  'FC4 1080 (category Grid)'],
            ['voltage_avg',   'Average voltage',                 'F', 'NRG.Volt',   false, 'total',  'FC4 1000/1002/1004 Ø'],
            ['current_avg',   'Average current (Grid)',             'F', 'NRG.Ampere',   false, 'total',  'FC4 1072/1074/1076 Ø'],
            ['energy_import', 'Active energy import',           'F', 'NRG.kWh', true,  'energy', 'FC4 1082 (Grid Ein, F64 Wh)'],
            ['energy_export', 'Active energy export',          'F', 'NRG.kWh', true,  'energy', 'FC4 1086 (Grid Aus, F64 Wh)'],
            ['connected',     'Connection',                 'B', '~Alert.Reversed', false, 'errors', ''],
        ];
        // Bewusst KEINE Frequenz: Register 1008 ist laut Doku „nicht
        // implementiert" und liefert am echten Gerät NaN.
    }

    public function getOptionalGroups()
    {
        $sensors = [];
        for ($i = 1; $i <= 6; $i++) {
            $b = 1010 + ($i - 1) * 2;
            $sensors[] = ['sens' . $i . '_i',  MHUB_Text::t('Sensor %d current', $i),           'F', 'NRG.Ampere',  false, 'current', 'FC4 ' . $b];
            $sensors[] = ['sens' . $i . '_p',  MHUB_Text::t('Sensor %d power', $i),        'F', 'NRG.Watt',  false, 'power',   'FC4 ' . ($b + 12)];
            $sensors[] = ['sens' . $i . '_pf', MHUB_Text::t('Sensor %d power factor', $i), 'F', 'MHB.PF', false, 'total',   'FC4 ' . ($b + 24)];
        }
        $cats = [];
        foreach ([['home', 'Home', 1046], ['car', 'Car', 1098], ['relais', MHUB_Text::t('Relay'), 1124], ['solar', 'Solar', 1150], ['akku', MHUB_Text::t('Battery storage'), 1176]] as [$key, $lbl, $base]) {
            $cats[] = [$key . '_power',         MHUB_Text::t('%s power', $lbl),    'F', 'NRG.Watt',   false, 'power',  'FC4 ' . ($base + 8)];
            $cats[] = [$key . '_energy_import', MHUB_Text::t('%s energy in', $lbl), 'F', 'NRG.kWh', true,  'energy', 'FC4 ' . ($base + 10) . ' (F64 Wh)'];
            $cats[] = [$key . '_energy_export', MHUB_Text::t('%s energy out', $lbl), 'F', 'NRG.kWh', true,  'energy', 'FC4 ' . ($base + 14) . ' (F64 Wh)'];
        }
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase (incl. N)', 'vars' => [
                ['u_l1_n', 'Voltage L1', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1000'],
                ['u_l2_n', 'Voltage L2', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1002'],
                ['u_l3_n', 'Voltage L3', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1004'],
                ['u_n',    'Voltage N',  'F', 'NRG.Volt', false, 'voltage', 'FC4 1006'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase (category Grid, incl. N)', 'vars' => [
                ['i_l1', 'Current L1', 'F', 'NRG.Ampere', false, 'current', 'FC4 1072'],
                ['i_l2', 'Current L2', 'F', 'NRG.Ampere', false, 'current', 'FC4 1074'],
                ['i_l3', 'Current L3', 'F', 'NRG.Ampere', false, 'current', 'FC4 1076'],
                ['i_n',  'Current N',  'F', 'NRG.Ampere', false, 'current', 'FC4 1078'],
            ]],
            'GroupGoeSensors'    => ['caption' => 'Current sensors 1-6 (current/power/power factor)', 'vars' => $sensors],
            'GroupGoeCategories' => ['caption' => 'Categories Home/Car/Relays/Solar/Battery (power + energy)', 'vars' => $cats],
        ];
    }

    public function getProfiles()    { return []; }
    public function getEnumProfiles(){ return []; }

    /** NaN-sichere Übernahme — unbelegte Register liefern 0xFFFF… statt Fehler. */
    private function put($hub, string $ident, float $v)
    {
        if (is_finite($v)) {
            $hub->SetVarFloat($ident, $v);
        }
    }

    private function putWh($hub, string $ident, float $wh)
    {
        if (is_finite($wh)) {
            $hub->SetVarEnergyWh($ident, $wh);
        }
    }

    public function readFast($mb, $hub)
    {
        // Ein Block deckt Spannungen, Sensoren und die Kategorien Home/Grid/
        // Car ab (1000..1124, 125 Register = Modbus-Maximum, live geprüft).
        $a = $mb->readInput(1000, 125);
        if ($a === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);

        $this->put($hub, 'power_total', $mb->readFloat32($a, 80));           // Grid P
        $this->put($hub, 'voltage_avg',
            ($mb->readFloat32($a, 0) + $mb->readFloat32($a, 2) + $mb->readFloat32($a, 4)) / 3.0);
        $this->put($hub, 'current_avg',
            ($mb->readFloat32($a, 72) + $mb->readFloat32($a, 74) + $mb->readFloat32($a, 76)) / 3.0);

        if ($hub->GroupActive('GroupVoltagePhase')) {
            $this->put($hub, 'u_l1_n', $mb->readFloat32($a, 0));
            $this->put($hub, 'u_l2_n', $mb->readFloat32($a, 2));
            $this->put($hub, 'u_l3_n', $mb->readFloat32($a, 4));
            $this->put($hub, 'u_n',    $mb->readFloat32($a, 6));
        }
        if ($hub->GroupActive('GroupCurrentPhase')) {
            $this->put($hub, 'i_l1', $mb->readFloat32($a, 72));
            $this->put($hub, 'i_l2', $mb->readFloat32($a, 74));
            $this->put($hub, 'i_l3', $mb->readFloat32($a, 76));
            $this->put($hub, 'i_n',  $mb->readFloat32($a, 78));
        }
        if ($hub->GroupActive('GroupGoeSensors')) {
            for ($i = 0; $i < 6; $i++) {
                $this->put($hub, 'sens' . ($i + 1) . '_i',  $mb->readFloat32($a, 10 + $i * 2));
                $this->put($hub, 'sens' . ($i + 1) . '_p',  $mb->readFloat32($a, 22 + $i * 2));
                $this->put($hub, 'sens' . ($i + 1) . '_pf', $mb->readFloat32($a, 34 + $i * 2));
            }
        }
        if ($hub->GroupActive('GroupGoeCategories')) {
            $this->put($hub, 'home_power', $mb->readFloat32($a, 54));  // 1046+8
            $this->put($hub, 'car_power',  $mb->readFloat32($a, 106)); // 1098+8
            // Relais/Solar/Akku liegen hinter Register 1124 → zweiter Block.
            $b = $mb->readInput(1124, 70); // 1124..1193
            if ($b !== null) {
                $this->put($hub, 'relais_power', $mb->readFloat32($b, 8));  // 1124+8
                $this->put($hub, 'solar_power',  $mb->readFloat32($b, 34)); // 1150+8
                $this->put($hub, 'akku_power',   $mb->readFloat32($b, 60)); // 1176+8
            }
        }
        return true;
    }

    public function readSlow($mb, $hub)
    {
        $a = $mb->readInput(1000, 125);
        if ($a === null) {
            return;
        }
        // Kategorie Grid = Netzübergabepunkt: Ein = Bezug, Aus = Einspeisung
        // (Zuordnung über die Zählerstände gegen die go-e-App geprüft).
        $this->putWh($hub, 'energy_import', $mb->readDouble64($a, 82)); // 1082
        $this->putWh($hub, 'energy_export', $mb->readDouble64($a, 86)); // 1086

        if ($hub->GroupActive('GroupGoeCategories')) {
            $this->putWh($hub, 'home_energy_import', $mb->readDouble64($a, 56));  // 1056
            $this->putWh($hub, 'home_energy_export', $mb->readDouble64($a, 60));  // 1060
            $this->putWh($hub, 'car_energy_import',  $mb->readDouble64($a, 108)); // 1108
            $this->putWh($hub, 'car_energy_export',  $mb->readDouble64($a, 112)); // 1112
            $b = $mb->readInput(1124, 70);
            if ($b !== null) {
                $this->putWh($hub, 'relais_energy_import', $mb->readDouble64($b, 10)); // 1134
                $this->putWh($hub, 'relais_energy_export', $mb->readDouble64($b, 14)); // 1138
                $this->putWh($hub, 'solar_energy_import',  $mb->readDouble64($b, 36)); // 1160
                $this->putWh($hub, 'solar_energy_export',  $mb->readDouble64($b, 40)); // 1164
                $this->putWh($hub, 'akku_energy_import',   $mb->readDouble64($b, 62)); // 1186
                $this->putWh($hub, 'akku_energy_export',   $mb->readDouble64($b, 66)); // 1190
            }
        }
    }
}

// ---------------------------------------------------------------------------
// MHUB_InexogyClient — OAuth-1.0a-Transport für die Inexogy-Cloud-API (ehem.
// Discovergy). Das Gegenstück zu MHUB_ModbusTcpClient für Cloud-Zähler: ein Treiber
// bekommt statt des Modbus-Clients diese Instanz und ruft getLastReading().
//
// Auth (an api.inexogy.com/docs geprüft, mit dem Verbund abgestimmt): Es gibt
// KEIN Basic Auth mehr (das alte Fremdmodul sprach die veraltete Domain
// discovergy.com an) und keinen Portal-API-Key — nur OAuth 1.0a HMAC-SHA1. Der
// ganze Handshake läuft programmatisch:
//   1. consumer_token  — Selbstregistrierung, liefert Consumer Key+Secret
//   2. request_token   — signiert mit dem Consumer
//   3. authorize       — E-Mail+Passwort des Nutzers → oauth_verifier
//   4. access_token    — Verifier → dauerhaftes Access-Token+Secret
// Danach wird NUR mit Consumer- und Access-Token signiert; das Passwort wird
// nach Schritt 3 sofort verworfen und nie gespeichert.
//
// Die Signierung ist in reinem PHP (hash_hmac), keine externe Bibliothek.
// ---------------------------------------------------------------------------

class MHUB_InexogyClient
{
    const BASE = 'https://api.inexogy.com/public/v1';

    private $consumerKey;
    private $consumerSecret;
    private $token;         // Access-Token (oder Request-Token im Handshake)
    private $tokenSecret;
    // Diagnosedetail des letzten http()-Aufrufs (curl-Fehler oder
    // HTTP-Status samt Antwortanfang) — für eine aussagekräftige
    // Fehlermeldung, wenn ein Handshake-Schritt fehlschlägt.
    private $lastError = '';
    /** HTTP-Statuscode des zuletzt ausgeführten http()-Aufrufs (0 = Netzwerkfehler/noch keiner). */
    private $lastHttpCode = 0;

    public function __construct(string $consumerKey = '', string $consumerSecret = '', string $token = '', string $tokenSecret = '')
    {
        $this->consumerKey    = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->token          = $token;
        $this->tokenSecret    = $tokenSecret;
    }

    // RFC-3986-Prozentkodierung (strenger als rawurlencode bei ~ nicht nötig,
    // aber wir folgen der Norm exakt, weil die Signatur sonst kippt).
    private static function enc($v): string
    {
        return str_replace(['+', '%7E'], ['%20', '~'], rawurlencode((string)$v));
    }

    /**
     * OAuth-1.0a-Signatur (HMAC-SHA1) für einen Request bilden und den
     * Authorization-Header zurückgeben. $extraOauth ergänzt/überschreibt
     * oauth_*-Parameter (z. B. oauth_verifier, oauth_callback).
     */
    private function authHeader(string $method, string $url, array $queryParams, array $extraOauth = []): string
    {
        $oauth = [
            'oauth_consumer_key'     => $this->consumerKey,
            'oauth_nonce'            => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string)time(),
            'oauth_version'          => '1.0',
        ];
        if ($this->token !== '') {
            $oauth['oauth_token'] = $this->token;
        }
        foreach ($extraOauth as $k => $v) {
            $oauth[$k] = $v;
        }

        // Signatur-Basisstring: alle oauth_- UND Query-Parameter, kodiert,
        // sortiert, verkettet.
        $all = array_merge($queryParams, $oauth);
        $pairs = [];
        foreach ($all as $k => $v) {
            $pairs[] = self::enc($k) . '=' . self::enc($v);
        }
        sort($pairs);
        $base = strtoupper($method) . '&' . self::enc($url) . '&' . self::enc(implode('&', $pairs));
        $key  = self::enc($this->consumerSecret) . '&' . self::enc($this->tokenSecret);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));

        $parts = [];
        foreach ($oauth as $k => $v) {
            $parts[] = self::enc($k) . '="' . self::enc($v) . '"';
        }
        return 'OAuth ' . implode(', ', $parts);
    }

    /**
     * HTTP-Request mit optionalem OAuth-Header. Rückgabe: [httpCode, body].
     * $params geht bei GET als Query-String an die URL (korrekte GET-
     * Semantik), bei jeder anderen Methode als form-kodierter Body
     * (CURLOPT_POSTFIELDS) — vorher landete es unabhängig von der Methode
     * IMMER im Query-String, auch bei POST. Für registerConsumer() (POST
     * mit echten Nutzdaten, kein leerer signierter Body wie die übrigen
     * POST-Aufrufe) bedeutete das: Inexogy bekam eine leere Anfrage ohne
     * das erwartete "client"-Feld — Ursache eines live gemeldeten HTTP 500
     * bei der Registrierung (Schritt 1/4).
     */
    private function http(string $method, string $url, array $params = [], string $authHeader = '', array $extraHeaders = []): array
    {
        $method = strtoupper($method);
        if ($method === 'GET') {
            $full = $url . (empty($params) ? '' : '?' . http_build_query($params));
            $ch = curl_init($full);
        } else {
            $ch = curl_init($url);
            if (!empty($params)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
            }
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        $headers = $extraHeaders;
        if ($authHeader !== '') {
            $headers[] = 'Authorization: ' . $authHeader;
        }
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        // Netzwerkfehler (DNS, Timeout, Verbindung abgelehnt …) und eine
        // echte HTTP-Antwort brauchen unterschiedliche Diagnose — beides
        // wird sonst gleich zu "false" verworfen und ist nicht mehr
        // unterscheidbar, sobald ein Schritt fehlschlägt.
        $this->lastHttpCode = $err !== '' ? 0 : $code;
        $this->lastError = $err !== ''
            ? MHUB_Text::t('Network error: %s', $err)
            : ($body !== false && $body !== '' ? MHUB_Text::t('HTTP %s – %s', $code, substr($body, 0, 200)) : MHUB_Text::t('HTTP %s (empty response)', $code));
        return [$code, $body === false ? '' : $body];
    }

    /** Diagnosedetail des zuletzt fehlgeschlagenen http()-Aufrufs. */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    /** HTTP-Statuscode des zuletzt fehlgeschlagenen http()-Aufrufs; 401 = Inexogy lehnt den Zugriffsschlüssel ab. */
    public function getLastHttpCode(): int
    {
        return $this->lastHttpCode;
    }

    // --- Handshake-Schritte (nur beim Login aufgerufen) --------------------

    /** Schritt 1: Consumer-Token selbst registrieren. Setzt Key/Secret. */
    public function registerConsumer(string $clientName): bool
    {
        // Content-Type und Accept exakt wie in der offiziellen Doku
        // (api.inexogy.com/docs) für diesen Schritt gefordert — curl würde
        // Content-Type bei einem String-Body zwar automatisch mitschicken,
        // hier aber lieber ausdrücklich statt implizit.
        [$code, $body] = $this->http('POST', self::BASE . '/oauth1/consumer_token', ['client' => $clientName], '', [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html, image/gif, image/jpeg, *; q=.2, */*; q=.2',
        ]);
        $j = json_decode($body, true);
        if ($code === 200 && isset($j['key'], $j['secret'])) {
            $this->consumerKey    = $j['key'];
            $this->consumerSecret = $j['secret'];
            return true;
        }
        return false;
    }

    /** Schritt 2: Request-Token holen (form-kodierte Antwort). */
    public function fetchRequestToken(): bool
    {
        $url = self::BASE . '/oauth1/request_token';
        $hdr = $this->authHeader('POST', $url, [], ['oauth_callback' => 'oob']);
        [$code, $body] = $this->http('POST', $url, [], $hdr);
        parse_str($body, $r);
        if ($code === 200 && isset($r['oauth_token'], $r['oauth_token_secret'])) {
            $this->token       = $r['oauth_token'];
            $this->tokenSecret = $r['oauth_token_secret'];
            return true;
        }
        return false;
    }

    /**
     * Schritt 3: Autorisieren mit E-Mail+Passwort → oauth_verifier.
     * Discovergy/Inexogy weicht hier vom Redirect-Standard ab und nimmt die
     * Zugangsdaten direkt entgegen. Das Passwort wird nur hier verwendet.
     */
    public function authorize(string $email, string $password): string
    {
        [$code, $body] = $this->http('GET', self::BASE . '/oauth1/authorize', [
            'oauth_token' => $this->token,
            'email'       => $email,
            'password'    => $password,
        ]);
        parse_str($body, $r);
        return ($code === 200 && isset($r['oauth_verifier'])) ? $r['oauth_verifier'] : '';
    }

    /** Schritt 4: Access-Token holen. Setzt das dauerhafte Token/Secret. */
    public function fetchAccessToken(string $verifier): bool
    {
        $url = self::BASE . '/oauth1/access_token';
        $hdr = $this->authHeader('POST', $url, [], ['oauth_verifier' => $verifier]);
        [$code, $body] = $this->http('POST', $url, [], $hdr);
        parse_str($body, $r);
        if ($code === 200 && isset($r['oauth_token'], $r['oauth_token_secret'])) {
            $this->token       = $r['oauth_token'];
            $this->tokenSecret = $r['oauth_token_secret'];
            return true;
        }
        return false;
    }

    public function getConsumerKey(): string    { return $this->consumerKey; }
    public function getConsumerSecret(): string { return $this->consumerSecret; }
    public function getToken(): string          { return $this->token; }
    public function getTokenSecret(): string    { return $this->tokenSecret; }

    // --- Datenabruf (mit Access-Token signiert) ----------------------------

    /** Zählerliste des Kontos. Rückgabe: Liste von Meter-Objekten (Array). */
    public function getMeters(): array
    {
        $url = self::BASE . '/meters';
        $hdr = $this->authHeader('GET', $url, []);
        [$code, $body] = $this->http('GET', $url, [], $hdr);
        $j = json_decode($body, true);
        return ($code === 200 && is_array($j)) ? $j : [];
    }

    /** Letzte Messung eines Zählers. Rückgabe: values-Objekt (Array) oder null. */
    public function getLastReading(string $meterId)
    {
        $url = self::BASE . '/last_reading';
        $hdr = $this->authHeader('GET', $url, ['meterId' => $meterId]);
        [$code, $body] = $this->http('GET', $url, ['meterId' => $meterId], $hdr);
        $j = json_decode($body, true);
        return ($code === 200 && isset($j['values']) && is_array($j['values'])) ? $j['values'] : null;
    }

    /**
     * Lastgang (Intervallmessungen) eines Zählers über einen Zeitraum.
     * $fromMs/$toMs in Millisekunden (Discovergy/Inexogy-Konvention, wie
     * bei allen Zeitangaben dieser API). $resolution eines von: raw,
     * three_minutes, fifteen_minutes, one_hour, one_day, one_week,
     * one_month, one_year (live per HTTP 400 gegengeprüft, NICHT die
     * Kurzform "15min" — siehe pydiscovergy/const.py Resolution-Enum).
     * Rückgabe: Liste von ['time' => ms, 'values' => [...]] oder [] bei
     * Fehlschlag — Semantik der values (kumulativ vs. Intervalldelta)
     * noch nicht verifiziert, siehe MHUB_DiagnoseInexogyReadings().
     */
    public function getReadings(string $meterId, int $fromMs, ?int $toMs, string $resolution = 'fifteen_minutes'): array
    {
        $url = self::BASE . '/readings';
        $params = ['meterId' => $meterId, 'from' => $fromMs, 'resolution' => $resolution];
        if ($toMs !== null) {
            $params['to'] = $toMs;
        }
        $hdr = $this->authHeader('GET', $url, $params);
        [$code, $body] = $this->http('GET', $url, $params, $hdr);
        $j = json_decode($body, true);
        return ($code === 200 && is_array($j)) ? $j : [];
    }
}

// ---------------------------------------------------------------------------
// MHUB_InexogyDriver — Cloud-Zähler über die Inexogy-API. Bekommt statt des
// MHUB_ModbusTcpClient einen MHUB_InexogyClient. Feldstruktur und Skalierung aus dem
// öffentlichen Quellcode des Alt-Moduls (elueckel/Discovergy_Smartmeter)
// verifiziert und gegen die Live-Werte von Dietmars Zähler gegengeprüft:
//   energy   /10^10 → kWh (Bezug, kumulativ)
//   energyOut/10^10 → kWh (Einspeisung, kumulativ)   — CamelCase-O im JSON!
//   power    /1000  → W    (Rohwert mW; Alt-Modul beschriftet fälschlich „kW")
//   power1/2/3 bzw. phase1Power/…  /1000 → W je Phase
//   voltage1/2/3 bzw. phase1Voltage/… /1000 → V je Phase
// Nur NON-OBIS (moderne Stromzähler). Kosten/Vergütung bewusst NICHT — reine
// Messung; die Rechnung macht das EMS/Tibber.
// ---------------------------------------------------------------------------

class MHUB_InexogyDriver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        // KEINE Frequenz — die API liefert keine.
        return [
            ['power_total',   'Total active power', 'F', 'NRG.Watt',   true,  'total',  'Inexogy values.power /1000'],
            ['energy_import', 'Active energy import',    'F', 'NRG.kWh', true,  'energy', 'Inexogy values.energy /1e10'],
            ['energy_export', 'Active energy export',   'F', 'NRG.kWh', true,  'energy', 'Inexogy values.energyOut /1e10'],
            ['connected',     'Connection',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase', 'vars' => [
                ['u_l1_n', 'Voltage L1', 'F', 'NRG.Volt', false, 'voltage', 'Inexogy voltage1 /1000'],
                ['u_l2_n', 'Voltage L2', 'F', 'NRG.Volt', false, 'voltage', 'Inexogy voltage2 /1000'],
                ['u_l3_n', 'Voltage L3', 'F', 'NRG.Volt', false, 'voltage', 'Inexogy voltage3 /1000'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Active power per phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'Inexogy power1 /1000'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'Inexogy power2 /1000'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'Inexogy power3 /1000'],
            ]],
        ];
    }

    public function getProfiles()    { return []; }
    public function getEnumProfiles(){ return []; }

    /** Ersten vorhandenen Feldnamen aus $v nehmen (Firmware-Varianten). */
    private static function pick(array $v, array $names)
    {
        foreach ($names as $n) {
            if (isset($v[$n]) && is_numeric($v[$n])) {
                return (float)$v[$n];
            }
        }
        return null;
    }

    public function readFast($client, $hub)
    {
        $v = $client->getLastReading($hub->InexogyMeterId());
        if ($v === null) {
            $hub->SetVarBool('connected', false);
            // 401 = Inexogy lehnt den gespeicherten Zugriffsschlüssel ab (laut
            // Inexogy-Doku läuft er irgendwann ab/wird ungültig, dann muss die
            // Anmeldung wiederholt werden) — vom reinen Netzwerk-/Serverfehler
            // unterscheiden, damit das Formular das sagen kann.
            $hub->NoteInexogyAuth($client->getLastHttpCode() === 401);
            return false;
        }
        $hub->NoteInexogyAuth(false);
        $hub->SetVarBool('connected', true);

        $p = self::pick($v, ['power']);
        if ($p !== null) {
            $hub->SetVarFloat('power_total', $p / 1000.0);
        }
        if ($hub->GroupActive('GroupPowerPhase')) {
            foreach ([['p_l1', ['power1', 'phase1Power']], ['p_l2', ['power2', 'phase2Power']], ['p_l3', ['power3', 'phase3Power']]] as [$id, $names]) {
                $x = self::pick($v, $names);
                if ($x !== null) { $hub->SetVarFloat($id, $x / 1000.0); }
            }
        }
        if ($hub->GroupActive('GroupVoltagePhase')) {
            foreach ([['u_l1_n', ['voltage1', 'phase1Voltage']], ['u_l2_n', ['voltage2', 'phase2Voltage']], ['u_l3_n', ['voltage3', 'phase3Voltage']]] as [$id, $names]) {
                $x = self::pick($v, $names);
                if ($x !== null) { $hub->SetVarFloat($id, $x / 1000.0); }
            }
        }
        return true;
    }

    public function readSlow($client, $hub)
    {
        $v = $client->getLastReading($hub->InexogyMeterId());
        if ($v === null) {
            return;
        }
        // Rohwert /10^10 → kWh; SetVarEnergykWh nimmt kWh entgegen.
        $imp = self::pick($v, ['energy']);
        $exp = self::pick($v, ['energyOut']);
        foreach ([['energy_import', $imp], ['energy_export', $exp]] as [$ident, $raw]) {
            if ($raw === null) {
                continue;
            }
            $kwh = $raw / 1e10;
            // Der Live-Zählerstand hinkt 15–20 min hinterher (Befund 12.09.2026:
            // um 16:18 kam der offizielle Wert von 16:00), wird aber mit dem
            // Ankunftszeitpunkt archiviert. Liegt er nicht über dem neuesten
            // archivierten (meist offiziellen) Stand, entstünde ein Rückschritt —
            // Symcons Zähler-Verdichtung zählt jeden davon doppelt. Nicht schreiben.
            $latest = method_exists($hub, 'LatestArchivedEnergyKWh') ? $hub->LatestArchivedEnergyKWh($ident) : null;
            if ($latest !== null && $kwh <= $latest) {
                continue;
            }
            $hub->SetVarEnergykWh($ident, $kwh);
        }
    }
}

// ---------------------------------------------------------------------------
// MHUB_BlueLogScadaInverterDriver — Meteocontrol blue'Log, SCADA-Schnittstelle
// (Lizenz 557.009), Wechselrichter-Registerblock.
//
// Ein blue'Log (Solarpark-Datenlogger) kann JEDES angeschlossene Gerät unter
// einer eigenen „SCADA-Adresse" im selben, herstellerweiten Registerschema
// anbieten — die Adresse selbst wird AUF DEM blue'Log konfiguriert
// (Geräteliste → Spalte „SCADA Adresse", Schaltflächen „SCADA Adressen
// ändern/zurücksetzen"), nicht in MeterHub. Adresse 97 ist reserviert für
// das blue'Log selbst (allgemeine Werte/Summen); jedes angeschlossene Gerät
// bekommt eine eigene, frei vergebene Adresse (an Dietmars Solarpark ab 100
// aufwärts) — diese Adresse ist die `UnitId` dieser Instanz.
//
// Live an Dietmars Solarpark verifiziert (07.09.2026, 48 Wechselrichter-
// Instanzen „WR <Feld>.1.<Kombiner>.<WR>", SCADA-Adressen 100–198): FC 0x03,
// Register 41000 (P_AC), Float32 wortgetauscht (CDAB) — `ByteOrder 3` direkt
// aus Symcons eigener ModBus-Address-Konfiguration an derselben Anlage
// abgelesen (Registerkarten: erst messen, dann glauben), nicht aus der
// Herstellerdoku angenommen. Registeradressen laut Meteocontrol „SCADA
// Interface Register V2.27.0" (557009-SCADA-interface).
// ---------------------------------------------------------------------------

class MHUB_BlueLogScadaInverterDriver implements MHUB_MeterDriverInterface
{
    /** Register 40000, Gerätetyp-Meldung — von beiden SCADA-Treibern geteilt. */
    public const DEVICE_TYPE_ENUM = [
        0 => ['Data logger (blue\'Log)', 0x808080],
        1 => ['Inverter',         0x00A000],
        2 => ['Sensor',                 0x0080C0],
        3 => ['Meter',                 0x0080C0],
        4 => ['String',                 0x808000],
        5 => ['Tracker',                0x808000],
        6 => ['Status DI external',       0x808080],
        7 => ['Genset',                 0xC08000],
        8 => ['Battery',               0x8000C0],
        9 => ['Kraftwerksregler',       0x808080],
    ];

    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung AC',           'F', 'NRG.Watt',   true,  'total',  'FC3 41000 (P_AC)'],
            ['energy_export', 'Total yield',             'F', 'NRG.kWh', true,  'energy', 'FC3 41066 (E_TOTAL, Wh)'],
            ['frequency',     'Grid frequency',              'F', 'MHB.Hz',  false, 'total',  'FC3 41012 (F_AC)'],
            ['device_type',   'Device type (SCADA message)', 'I', 'MHB.BlueLogDeviceType', false, 'total', 'FC3 40000'],
            ['connected',     'Connection',                'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase (AC)', 'vars' => [
                ['u_l1_n', 'Voltage L1', 'F', 'NRG.Volt', false, 'voltage', 'FC3 41040 (U_AC1)'],
                ['u_l2_n', 'Voltage L2', 'F', 'NRG.Volt', false, 'voltage', 'FC3 41042 (U_AC2)'],
                ['u_l3_n', 'Voltage L3', 'F', 'NRG.Volt', false, 'voltage', 'FC3 41044 (U_AC3)'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase (AC)', 'vars' => [
                ['i_l1', 'Current L1', 'F', 'NRG.Ampere', false, 'current', 'FC3 41052 (I_AC1)'],
                ['i_l2', 'Current L2', 'F', 'NRG.Ampere', false, 'current', 'FC3 41054 (I_AC2)'],
                ['i_l3', 'Current L3', 'F', 'NRG.Ampere', false, 'current', 'FC3 41056 (I_AC3)'],
            ]],
            'GroupDc' => ['caption' => 'DC side (total of all strings)', 'vars' => [
                ['p_dc', 'DC power', 'F', 'NRG.Watt',   false, 'dc', 'FC3 41080 (P_DC)'],
                ['u_dc', 'DC voltage', 'F', 'NRG.Volt',   false, 'dc', 'FC3 41082 (U_DC)'],
                ['i_dc', 'DC current',    'F', 'NRG.Ampere', false, 'dc', 'FC3 41084 (I_DC)'],
            ]],
        ];
    }

    public function getProfiles() { return []; }

    public function getEnumProfiles()
    {
        return ['MHB.BlueLogDeviceType' => self::DEVICE_TYPE_ENUM];
    }

    public function readFast($mb, $hub)
    {
        // blue'Log SCADA liefert Float32 immer wortgetauscht (CDAB) — fest für
        // dieses Interface, unabhängig vom nutzerseitigen WordSwap-Schalter.
        $mb->setWordSwap(true);

        $r = $mb->readHolding(41000, 14); // 41000..41013 (P_AC .. F_AC)
        if ($r === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);
        $hub->SetVarFloat('power_total', $mb->readFloat32($r, 0));  // 41000
        $hub->SetVarFloat('frequency',   $mb->readFloat32($r, 12)); // 41012

        $t = $mb->readHolding(40000, 1);
        if ($t !== null) {
            $hub->SetVarInt('device_type', $mb->u16($t, 0));
        }

        if ($hub->GroupActive('GroupVoltagePhase') || $hub->GroupActive('GroupCurrentPhase')) {
            $p = $mb->readHolding(41040, 18); // 41040..41057
            if ($p !== null) {
                if ($hub->GroupActive('GroupVoltagePhase')) {
                    $hub->SetVarFloat('u_l1_n', $mb->readFloat32($p, 0));  // 41040
                    $hub->SetVarFloat('u_l2_n', $mb->readFloat32($p, 2));  // 41042
                    $hub->SetVarFloat('u_l3_n', $mb->readFloat32($p, 4));  // 41044
                }
                if ($hub->GroupActive('GroupCurrentPhase')) {
                    $hub->SetVarFloat('i_l1', $mb->readFloat32($p, 12)); // 41052
                    $hub->SetVarFloat('i_l2', $mb->readFloat32($p, 14)); // 41054
                    $hub->SetVarFloat('i_l3', $mb->readFloat32($p, 16)); // 41056
                }
            }
        }
        if ($hub->GroupActive('GroupDc')) {
            $d = $mb->readHolding(41080, 6); // 41080..41085
            if ($d !== null) {
                $hub->SetVarFloat('p_dc', $mb->readFloat32($d, 0)); // 41080
                $hub->SetVarFloat('u_dc', $mb->readFloat32($d, 2)); // 41082
                $hub->SetVarFloat('i_dc', $mb->readFloat32($d, 4)); // 41084
            }
        }
        return true;
    }

    // Energie: E_TOTAL (41066, kumulativer Ertrag in Wh). E_DAY (41064) wird
    // bewusst NICHT verwendet — springt täglich auf 0 zurück, taugt laut
    // Verbund-Konvention nicht für einen kumulativen Zähler.
    public function readSlow($mb, $hub)
    {
        $mb->setWordSwap(true);
        $r = $mb->readHolding(41066, 2);
        if ($r === null) {
            return;
        }
        $hub->SetVarEnergyWh('energy_export', $mb->readFloat32($r, 0)); // 41066
    }
}

// ---------------------------------------------------------------------------
// MHUB_BlueLogScadaMeterDriver — Meteocontrol blue'Log, SCADA-Schnittstelle,
// Zähler-Registerblock (43000) statt „Inverter" (41000) — sonst dasselbe
// Interface, dieselbe geteilte Geräteliste am blue'Log (siehe
// MHUB_BlueLogScadaInverterDriver für die volle Herleitung der SCADA-Adresse).
//
// Byte-Reihenfolge (CDAB) und der allgemeine Adress-/FC3-Mechanismus sind
// live an Dietmars Solarpark verifiziert (siehe Inverter-Treiber) — die
// konkreten 43000er-Registeradressen selbst laufen dort aber über keinen
// physisch angeschlossenen SCADA-Zähler (Gerätetyp 3); es existierte an
// dieser Anlage kein Prüfobjekt dafür. Sie stammen unverändert aus derselben
// Meteocontrol-Quelle „SCADA Interface Register V2.27.0" wie der verifizierte
// Inverter-Block — vor dem ersten produktiven Einsatz an einem echten Zähler
// gegen die Geräteanzeige abgleichen.
// ---------------------------------------------------------------------------

class MHUB_BlueLogScadaMeterDriver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Total active power',       'F', 'NRG.Watt',   true,  'total',  'FC3 43000 (M_AC_P)'],
            ['energy_import', 'Active energy import',          'F', 'NRG.kWh', true,  'energy', 'FC3 43066 (M_AC_E_IMP, Wh)'],
            ['energy_export', 'Active energy export',         'F', 'NRG.kWh', true,  'energy', 'FC3 43064 (M_AC_E_EXP, Wh)'],
            ['frequency',     'Grid frequency',              'F', 'MHB.Hz',  false, 'total',  'FC3 43014 (M_AC_F)'],
            ['device_type',   'Device type (SCADA message)', 'I', 'MHB.BlueLogDeviceType', false, 'total', 'FC3 40000'],
            ['connected',     'Connection',                'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Voltage per phase', 'vars' => [
                ['u_l1_n', 'Voltage L1', 'F', 'NRG.Volt', false, 'voltage', 'FC3 43040 (M_AC_U1)'],
                ['u_l2_n', 'Voltage L2', 'F', 'NRG.Volt', false, 'voltage', 'FC3 43042 (M_AC_U2)'],
                ['u_l3_n', 'Voltage L3', 'F', 'NRG.Volt', false, 'voltage', 'FC3 43044 (M_AC_U3)'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Current per phase', 'vars' => [
                ['i_l1', 'Current L1', 'F', 'NRG.Ampere', false, 'current', 'FC3 43052 (M_AC_I1)'],
                ['i_l2', 'Current L2', 'F', 'NRG.Ampere', false, 'current', 'FC3 43054 (M_AC_I2)'],
                ['i_l3', 'Current L3', 'F', 'NRG.Ampere', false, 'current', 'FC3 43056 (M_AC_I3)'],
            ]],
            'GroupReactiveApparent' => ['caption' => 'Reactive/apparent power / power factor', 'vars' => [
                ['q_total',  'Total reactive power',   'F', 'MHB.var', false, 'power', 'FC3 43002 (M_AC_Q)'],
                ['s_total',  'Total apparent power',  'F', 'MHB.VA',  false, 'power', 'FC3 43004 (M_AC_S)'],
                ['pf_total', 'Total power factor', 'F', 'MHB.PF',  false, 'total', 'FC3 43006 (M_AC_PF_COSPHI)'],
            ]],
        ];
    }

    public function getProfiles() { return []; }

    public function getEnumProfiles()
    {
        return ['MHB.BlueLogDeviceType' => MHUB_BlueLogScadaInverterDriver::DEVICE_TYPE_ENUM];
    }

    public function readFast($mb, $hub)
    {
        $mb->setWordSwap(true); // blue'Log SCADA: Float32 immer CDAB, live verifiziert
        $r = $mb->readHolding(43000, 16); // 43000..43015 (M_AC_P .. M_AC_F)
        if ($r === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);
        $hub->SetVarFloat('power_total', $mb->readFloat32($r, 0));  // 43000
        $hub->SetVarFloat('frequency',   $mb->readFloat32($r, 14)); // 43014

        $t = $mb->readHolding(40000, 1);
        if ($t !== null) {
            $hub->SetVarInt('device_type', $mb->u16($t, 0));
        }

        if ($hub->GroupActive('GroupReactiveApparent')) {
            $hub->SetVarFloat('q_total',  $mb->readFloat32($r, 2)); // 43002
            $hub->SetVarFloat('s_total',  $mb->readFloat32($r, 4)); // 43004
            $hub->SetVarFloat('pf_total', $mb->readFloat32($r, 6)); // 43006
        }

        if ($hub->GroupActive('GroupVoltagePhase') || $hub->GroupActive('GroupCurrentPhase')) {
            $p = $mb->readHolding(43040, 18); // 43040..43057
            if ($p !== null) {
                if ($hub->GroupActive('GroupVoltagePhase')) {
                    $hub->SetVarFloat('u_l1_n', $mb->readFloat32($p, 0));  // 43040
                    $hub->SetVarFloat('u_l2_n', $mb->readFloat32($p, 2));  // 43042
                    $hub->SetVarFloat('u_l3_n', $mb->readFloat32($p, 4));  // 43044
                }
                if ($hub->GroupActive('GroupCurrentPhase')) {
                    $hub->SetVarFloat('i_l1', $mb->readFloat32($p, 12)); // 43052
                    $hub->SetVarFloat('i_l2', $mb->readFloat32($p, 14)); // 43054
                    $hub->SetVarFloat('i_l3', $mb->readFloat32($p, 16)); // 43056
                }
            }
        }
        return true;
    }

    public function readSlow($mb, $hub)
    {
        $mb->setWordSwap(true);
        $r = $mb->readHolding(43064, 4); // M_AC_E_EXP (43064) + M_AC_E_IMP (43066)
        if ($r === null) {
            return;
        }
        $hub->SetVarEnergyWh('energy_export', $mb->readFloat32($r, 0)); // 43064
        $hub->SetVarEnergyWh('energy_import', $mb->readFloat32($r, 2)); // 43066
    }
}

// ---------------------------------------------------------------------------
// MHUB_BlueLogScadaLoggerDriver — Meteocontrol blue'Log, SCADA-Schnittstelle,
// Adresse 97: das blue'Log selbst (Gerätetyp 0 = Datenlogger) mit seinen
// berechneten Werten. Dietmars Vorgabe 11.09.2026: „Die 97 muss definitiv
// erscheinen, weil die 97 die Zusammenfassung des kompletten Datenloggers
// darstellt."
//
// Registerkarte laut „SCADA Interface Register V2.27.0" (2026-05-29,
// Abschnitt BLUE'LOG, Help Center → „SCADA Interface blue'Log XM/XC license
// data sheet"): 10000 P_AC_INV_SUM (Summe der AC-Leistung aller
// Wechselrichter, W; ein blue'Log XC als Master summiert auch die seiner
// Slaves), 10004/10006 PPC_INV_INST/PPC_INV_AVAIL (installierte/aktive
// Wechselrichter), 10008/10010 PPC_P_AC_AVAIL/PPC_Q_AC_AVAIL (verfügbare
// Wirk-/Blindleistung), 10100 PPC_P_SET_REL (Wirkleistungs-Sollwert, %) —
// alle F32, ab SCADA 2.25; keine Schreib-Sektion für 97. Die ältere V2.24.0
// kannte nur 10000.
//
// Live gemessen (Solarpark, 11.09.2026): blue'Log .201 (XM-3000): 40000 = 0,
// 10000/10001 = 9AD0 490A → CDAB = 567 725 W, übereinstimmend mit Dietmars
// eigener Symcon-Abfrage (#31777: FC3, Adresse 10000, Real, ByteOrder 3);
// 10004/10006 = 31/31 — per Modbus-Zählung bestätigt (31 Wechselrichter an
// SCADA-Adresse 127–157); 10008/10010/10100 NaN (keine Leistungsregelung).
// .212 (XC-10000): 2,40 MW, 276/271 WR, 3,50 MW/3,26 Mvar verfügbar,
// Sollwert 100 %. .217 (XC-20000): 4,06 MW, 440/432 WR.
//
// Kein Zählerstand im Gerät: die SCADA-Schnittstelle dokumentiert unter 97
// keine Energie. Der Ertrag wird deshalb von MeterHub aus der Park-Leistung
// hochgerechnet (MHUB_CalculatedEnergyDriverInterface, Dietmars Auftrag
// 11.09.2026). Rein lesend; Power Control/RPC bleiben eigene, bewusst von
// Hand anzulegende Zählertypen (keine zwei Schreiber).
// ---------------------------------------------------------------------------

class MHUB_BlueLogScadaLoggerDriver implements MHUB_MeterDriverInterface, MHUB_CalculatedEnergyDriverInterface
{
    public function calculatedEnergy(): array
    {
        // Park-Leistung ist Erzeugung (positiv) → Ertrag = Abgabe-Zähler,
        // wie E_TOTAL beim Wechselrichter-Treiber.
        return ['energy_export' => 'power_total'];
    }

    public function getBaseVars()
    {
        return [
            ['power_total',   'AC active power (total of all inverters)', 'F', 'NRG.Watt', true,  'total', 'FC3 10000 (P_AC_INV_SUM, address 97)'],
            // Ab SCADA 2.25 (Doku V2.27.0). Als Ganzzahl ohne Profil — der
            // Unterschied installiert/aktiv zeigt ausgefallene Wechselrichter.
            ['energy_export', 'Total yield (extrapolated)', 'F', 'NRG.kWh', true, 'energy', 'extrapolated: power × time from FC3 10000 (no meter reading in the device)'],
            ['inv_installed', 'Inverters installed',  'I', '', true,  'total', 'FC3 10004 (PPC_INV_INST)'],
            ['inv_active',    'Inverter active',        'I', '', true,  'total', 'FC3 10006 (PPC_INV_AVAIL)'],
            ['device_type',   'Device type (SCADA message)',   'I', 'MHB.BlueLogDeviceType', false, 'total', 'FC3 40000'],
            ['connected',     'Connection',                  'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        // Nur bei einem blue'Log mit Leistungsregelung (XC-Master) belegt —
        // an Dietmars XM-3000 NaN, an den XC-Reglern .212/.217 gefüllt.
        // Rein lesend: die Doku sieht unter 97 keinen Schreibzugriff vor.
        return [
            'GroupPpc' => ['caption' => 'Power control (blue\'Log with Power Control)', 'vars' => [
                ['p_available',    'Available active power',    'F', 'NRG.Watt',    true, 'power', 'FC3 10008 (PPC_P_AC_AVAIL)'],
                ['q_available',    'Available reactive power',   'F', 'MHB.var',     true, 'power', 'FC3 10010 (PPC_Q_AC_AVAIL)'],
                ['p_setpoint_rel', 'Active power setpoint',     'F', 'NRG.Percent', true, 'power', 'FC3 10100 (PPC_P_SET_REL)'],
            ]],
        ];
    }

    public function getProfiles() { return []; }

    public function getEnumProfiles()
    {
        return ['MHB.BlueLogDeviceType' => MHUB_BlueLogScadaInverterDriver::DEVICE_TYPE_ENUM];
    }

    public function readFast($mb, $hub)
    {
        $mb->setWordSwap(true); // blue'Log SCADA: Float32 immer CDAB, live verifiziert
        // Ein Block 10000..10011 (P_AC_INV_SUM, reserviert, INV_INST,
        // INV_AVAIL, P_AC_AVAIL, Q_AC_AVAIL) — lückenlos laut Doku, live an
        // XM-3000 und XC-10000/20000 ohne Fehler gelesen.
        $r = $mb->readHolding(10000, 12);
        if ($r === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);
        // Fehlender Wert = 0xFFFFFFFF bzw. 0x7FC00000 (NaN) laut Datenblatt —
        // nicht als Zahl schreiben, der letzte gültige Wert bleibt stehen.
        $p = $mb->readFloat32($r, 0);
        if (is_finite($p)) {
            $hub->SetVarFloat('power_total', $p);
        }
        foreach ([4 => 'inv_installed', 6 => 'inv_active'] as $off => $ident) {
            $n = $mb->readFloat32($r, $off);
            if (is_finite($n)) {
                $hub->SetVarInt($ident, (int)round($n));
            }
        }
        if ($hub->GroupActive('GroupPpc')) {
            foreach ([8 => 'p_available', 10 => 'q_available'] as $off => $ident) {
                $v = $mb->readFloat32($r, $off);
                if (is_finite($v)) {
                    $hub->SetVarFloat($ident, $v);
                }
            }
            $s = $mb->readHolding(10100, 2);
            if ($s !== null) {
                $v = $mb->readFloat32($s, 0);
                if (is_finite($v)) {
                    $hub->SetVarFloat('p_setpoint_rel', $v);
                }
            }
        }
        $t = $mb->readHolding(40000, 1);
        if ($t !== null) {
            $hub->SetVarInt('device_type', $mb->u16($t, 0));
        }
        return true;
    }

    // Kein dokumentierter Zählerstand unter Adresse 97 — der Ertrag entsteht
    // in MeterHub::AdvanceCalculatedEnergy() nach jedem readFast().
    public function readSlow($mb, $hub)
    {
    }
}

// ---------------------------------------------------------------------------
// MHUB_BlueLogRpcDriver — Meteocontrol blue'Log, Remote Power Control (RPC),
// Slave-ID 10. Für den Direktvermarkter/„3rd party" gedachte Sollwert-
// Schnittstelle — genau diese Rolle übernimmt eine Instanz mit diesem
// Zählertyp. Ersetzt (Dietmars ausdrücklicher Auftrag, 07.09.2026, nach
// Rücksprache mit ModbusSlave/EMS) die bisher in Ad-hoc-PHP-Skripten
// vergrabene „Regelung der blue'Log Master"-Logik seines Solarparks.
//
// MeterHub übernimmt bewusst NUR das Halten/Schreiben des Sollwerts — die
// eigentliche Berechnung (z. B. kapazitätsgewichtete Aggregation mehrerer
// Direktvermarkter-Quellwerte) bleibt außerhalb: ein Skript/EMS ruft
// `MHUB_SetBlueLogTarget($id, $wert)`.
//
// ⚠️ Schreibt auf ein reales Kontrollinterface einer kommerziellen Anlage.
// Kollidiert mit jedem anderen System, das denselben Kanal (Slave-ID 10)
// bereits bedient — vor der ersten Aktivierung sicherstellen, dass kein
// zweiter Schreiber aktiv ist (siehe Formular-Warnung).
//
// Sicherheitsnetz des blue'Log selbst: ein geschriebener Sollwert bleibt nur
// die Gültigkeitszeit (Register 5006, hier konfigurierbar) lang wirksam und
// fällt danach automatisch in den Normalbetrieb zurück, wenn niemand
// nachschreibt — deshalb schreibt dieser Treiber bei JEDEM Zyklus erneut,
// auch wenn sich der Zielwert nicht geändert hat.
// ---------------------------------------------------------------------------

class MHUB_BlueLogRpcDriver implements MHUB_MeterDriverInterface, MHUB_WritableMeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',        'Feed-in power (actual)',        'F', 'NRG.Watt', true,  'total', 'FC3 2 (PPC_P_AC_FEED_IN)'],
            ['active_setpoint_rel', 'Effective setpoint (actual, %)',    'F', 'NRG.Percent', false, 'total', 'FC3 8 (PPC_P_SET_RPC_REL)'],
            ['frequency',          'Grid frequency',                   'F', 'MHB.Hz',   false, 'total', 'FC3 42 (PPC_F_AC)'],
            ['connected',          'Connection',                     'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups() { return []; }
    public function getProfiles()       { return []; }
    public function getEnumProfiles()   { return []; }

    public function readFast($mb, $hub)
    {
        $mb->setWordSwap(true);
        $r = $mb->readHolding(0, 44); // 0..43, deckt FEED_IN(2)/RPC_REL(8)/F_AC(42) ab
        if ($r === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);
        // Herstellerkonvention: negativ = Bezug, positiv = Einspeisung — genau
        // umgekehrt zu MeterHubs eigener Konvention (+ = Bezug). Fest
        // invertiert, kein Fall für den nutzerseitigen PowerInvert-Schalter
        // (der ist für Verdrahtungsfehler gedacht, nicht für eine pro
        // Schnittstelle fest umgekehrte Herstellerkonvention).
        $hub->SetVarFloat('power_total', -$mb->readFloat32($r, 2));   // 2
        $hub->SetVarFloat('active_setpoint_rel', $mb->readFloat32($r, 8)); // 8
        $hub->SetVarFloat('frequency', $mb->readFloat32($r, 42));     // 42
        return true;
    }

    public function readSlow($mb, $hub) { /* keine kumulativen Energiezähler im RPC-Leseblock */ }

    public function writeSetpoint($mb, $hub): void
    {
        $mb->setWordSwap(true);
        $reg = $hub->SetpointMode() === 'abs' ? 5002 : 5000; // ABS bzw. REL
        $mb->writeHolding($reg, $mb->packFloat32($hub->TargetSetpoint()));
        // Gültigkeitszeit (Minuten) mitschreiben — unabhängig vom Poll-Takt
        // dieser Instanz auf dem vom Nutzer gewünschten Wert gehalten.
        $mb->writeHolding(5006, $mb->packFloat32((float)$hub->RpcValidTimeMin()));
    }

    public function writeFailsafe($mb, $hub): void
    {
        $mb->setWordSwap(true);
        // Immer REL — ein universelles "Normalbetrieb"-Signal, unabhängig
        // vom sonst gewählten Sollwert-Modus (ABS bräuchte die vereinbarte
        // Anschlussleistung, um "voll" auszudrücken; REL 100 % ist eindeutig).
        $mb->writeHolding(5000, $mb->packFloat32($hub->FailsafeDefaultPercent()));
    }
}

// ---------------------------------------------------------------------------
// MHUB_BlueLogPowerControlDriver — Meteocontrol blue'Log, Modbus Power
// Control, Slave-ID 1. Für den NETZBETREIBER gedachte Sollwert-Schnittstelle
// — anders als RPC übernimmt diese Instanz damit eine Rolle, die eigentlich
// dem Netzbetreiber gehört. Nur aktivieren, wenn diese Rolle tatsächlich
// zutrifft (siehe Formular-Warnung).
//
// ⚠️ Kein Watchdog/Gültigkeitszeit-Mechanismus laut Herstellerdoku (anders
// als RPC) — ein gesetzter Wert bleibt stehen, bis er aktiv überschrieben
// wird. Fällt MeterHub selbst aus, gibt es hier KEIN automatisches
// Zurückfallen in den Normalbetrieb.
// ---------------------------------------------------------------------------

class MHUB_BlueLogPowerControlDriver implements MHUB_MeterDriverInterface, MHUB_WritableMeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total', 'Active power (actual, grid connection point)', 'F', 'NRG.Watt', true,  'total', 'FC3 90 (PPC_P_AC)'],
            ['frequency',   'Grid frequency',                          'F', 'MHB.Hz',   false, 'total', 'FC3 98 (PPC_F_AC)'],
            ['connected',   'Connection',                            'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups() { return []; }
    public function getProfiles()       { return []; }
    public function getEnumProfiles()   { return []; }

    public function readFast($mb, $hub)
    {
        $mb->setWordSwap(true);
        $r = $mb->readHolding(90, 10); // 90..99, deckt P_AC(90)/F_AC(98) ab
        if ($r === null) {
            $hub->SetVarBool('connected', false);
            return false;
        }
        $hub->SetVarBool('connected', true);
        // Herstellerkonvention: negativ = Bezug, positiv = Einspeisung — s.
        // MHUB_BlueLogRpcDriver::readFast() für die Begründung der Invertierung.
        $hub->SetVarFloat('power_total', -$mb->readFloat32($r, 0)); // 90
        $hub->SetVarFloat('frequency', $mb->readFloat32($r, 8));    // 98
        return true;
    }

    public function readSlow($mb, $hub) { /* keine kumulativen Energiezähler im gelesenen Block */ }

    public function writeSetpoint($mb, $hub): void
    {
        $mb->setWordSwap(true);
        $reg = $hub->SetpointMode() === 'abs' ? 5006 : 5000; // ABS bzw. REL
        $mb->writeHolding($reg, $mb->packFloat32($hub->TargetSetpoint()));
    }

    public function writeFailsafe($mb, $hub): void
    {
        $mb->setWordSwap(true);
        $mb->writeHolding(5000, $mb->packFloat32($hub->FailsafeDefaultPercent())); // immer REL
    }
}

// ---------------------------------------------------------------------------
// MeterHub — Hauptmodul, lädt den Treiber laut Meter-Property
// ---------------------------------------------------------------------------

class MeterHub extends IPSModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        MHUB_Text::set(function (string $text): string {
            return $this->Translate($text);
        });
    }

    private const DRIVERS = [
        'siemens_pac2200' => 'MHUB_Pac2200Driver',
        'janitza_umg604'  => 'MHUB_JanitzaClassicDriver',
        'janitza_umg605'  => 'MHUB_JanitzaClassicDriver',
        'janitza_umg509'  => 'MHUB_JanitzaClassicDriver',
        'janitza_umg512'  => 'MHUB_JanitzaClassicDriver',
        'janitza_umg806'  => 'MHUB_JanitzaClassicDriver',
        'janitza_umg96pa' => 'MHUB_JanitzaClassicDriver',
        'janitza_umg801'  => 'MHUB_JanitzaClassicDriver',
        'janitza_umg800'  => 'MHUB_Umg800Driver',
        'eastron_sdm72d'  => 'MHUB_EastronSdmDriver',
        'eastron_sdm630'  => 'MHUB_EastronSdmDriver',
        'eastron_sdm120'  => 'MHUB_EastronSdmSinglePhaseDriver',
        'eastron_sdm220'  => 'MHUB_EastronSdmSinglePhaseDriver',
        'eastron_sdm230'  => 'MHUB_EastronSdmSinglePhaseDriver',
        'whatwatt'        => 'MHUB_WhatWattDriver',
        'phoenix_eem375'  => 'MHUB_PhoenixEem375Driver',
        'phoenix_eemxm'   => 'MHUB_PhoenixEemXmDriver',
        'carlo_gavazzi_em' => 'MHUB_CarloGavazziDriver',
        'socomec_countis'  => 'MHUB_SocomecCountisDriver',
        'mbs_professional' => 'MHUB_MbsProfessionalDriver',
        'shelly_pro3em'    => 'MHUB_ShellyPro3emDriver',
        'goe_controller'   => 'MHUB_GoeControllerDriver',
        'inexogy'          => 'MHUB_InexogyDriver',
        'bluelog_scada_inverter' => 'MHUB_BlueLogScadaInverterDriver',
        'bluelog_scada_meter'    => 'MHUB_BlueLogScadaMeterDriver',
        'bluelog_scada_logger'   => 'MHUB_BlueLogScadaLoggerDriver',
        'bluelog_rpc'            => 'MHUB_BlueLogRpcDriver',
        'bluelog_powercontrol'   => 'MHUB_BlueLogPowerControlDriver',
    ];

    private const METER_LABELS = [
        'siemens_pac2200' => 'Siemens PAC2200',
        'janitza_umg604'  => 'Janitza UMG 604',
        'janitza_umg605'  => 'Janitza UMG 605',
        'janitza_umg509'  => 'Janitza UMG 509',
        'janitza_umg512'  => 'Janitza UMG 512',
        'janitza_umg806'  => 'Janitza UMG 806',
        'janitza_umg96pa' => 'Janitza UMG 96PA',
        'janitza_umg801'  => 'Janitza UMG 801',
        'janitza_umg800'  => 'Janitza UMG 800',
        'eastron_sdm72d'  => 'Eastron SDM72D-M v2',
        'eastron_sdm630'  => 'Eastron SDM630 v2',
        'eastron_sdm120'  => 'Eastron SDM120',
        'eastron_sdm220'  => 'Eastron SDM220',
        'eastron_sdm230'  => 'Eastron SDM230',
        'whatwatt'        => 'WhatWatt',
        'phoenix_eem375'  => 'Phoenix Contact EEM-EM375',
        'phoenix_eemxm'   => 'Phoenix Contact EEM-XM',
        'carlo_gavazzi_em' => 'Carlo Gavazzi EM24 / EM300 / ET340',
        'socomec_countis'  => 'Socomec Countis',
        'mbs_professional' => 'MBS Professional 3-75',
        'shelly_pro3em'    => 'Shelly Pro 3EM',
        'goe_controller'   => 'go-e Controller',
        'inexogy'          => 'Inexogy / Discovergy (Cloud)',
        'bluelog_scada_inverter' => 'Meteocontrol blue\'Log SCADA – inverter',
        'bluelog_scada_meter'    => 'Meteocontrol blue\'Log SCADA – meter',
        'bluelog_scada_logger'   => 'Meteocontrol blue\'Log SCADA – data logger (total, address 97)',
        'bluelog_rpc'            => 'Meteocontrol blue\'Log RPC (direct marketer, ⚠️ write access)',
        'bluelog_powercontrol'   => 'Meteocontrol blue\'Log Power Control (grid operator, ⚠️ write access)',
    ];

    // Funktions-Vokabular für die Zuordnung „welcher Verbraucher hängt hier?".
    // [Schlüssel => [Anzeigename, IPS-Icon]]. Bewusst an den Verbraucher-Arten
    // der InverterHubTile-Kachel orientiert, damit andere Module die Zuordnung
    // direkt übernehmen können.
    private const FUNCTIONS = [
        'none'       => ['— no assignment —',      ''],
        // Anlage / Infrastruktur
        'grid'       => ['Grid connection',            'Electricity'],
        'house'      => ['House consumption',            'HollowHouse'],
        'pv'         => ['PV generation',             'Sun'],
        'battery'    => ['Battery',                 'Battery'],
        // Wärme / Klima
        'heatpump'   => ['Heat pump',               'Temperature'],
        'heater'     => ['Heating / heating element',       'Temperature'],
        'hotwater'   => ['Hot water',               'Drops'],
        'aircon'     => ['Air conditioning',              'Snowflake'],
        'ventilation'=> ['Ventilation',                  'Ventilation'],
        // Mobilität
        'wallbox1'   => ['Wallbox 1',                'Car'],
        'wallbox2'   => ['Wallbox 2',                'Car'],
        'wallbox3'   => ['Wallbox 3',                'Car'],
        'wallbox4'   => ['Wallbox 4',                'Car'],
        'wallbox5'   => ['Wallbox 5',                'Car'],
        'garage'     => ['Garage',                   'Car'],
        // Haushaltsgeräte
        'washer'     => ['Washing machine',            'Drops'],
        'dryer'      => ['Dryer',                 'Wind'],
        'dishwasher' => ['Dishwasher',             'Drops'],
        'oven'       => ['Backofen',                 'Flame'],
        'stove'      => ['Stove',                     'Flame'],
        'fridge'     => ['Refrigerator/freezer',       'Snowflake'],
        'kitchen'    => ['Kitchen (total)',           'Gear'],
        'appliances' => ['Household appliances (general)', 'Plug'],
        // Sonstige Bereiche
        'pool'       => ['Pool',                     'Waves'],
        'sauna'      => ['Sauna',                    'Flame'],
        'light'      => ['Lighting',              'Bulb'],
        'it'         => ['Server / network',        'Gear'],
        'entertainment' => ['Unterhaltungsmedien',   'TV'],
        'workshop'   => ['Werkstatt',                'Gear'],
        'other'      => ['Other consumer',    'Electricity'],
    ];

    private $driver = null;
    /** Ident => Funktionszuordnung, in RegisterVariables() aufgelöst. */
    private $funcMap = [];

    public function Create()
    {
        parent::Create();

        // ConnectParent(MHUB_ModbusGatewayClient::GATEWAY_GUID) hier NICHT
        // unbedingt aufrufen (SUITE.md 9j) — laut SDK-Doku erstellt/verbindet
        // die Funktion bei Bedarf selbst eine passende Parent-Instanz, was
        // JEDE bestehende Direktverbindungs-Instanz beim nächsten Modul-Update
        // ungefragt mit einem Modbus-Gateway verknüpfen könnte (InverterHubs
        // Fund 18.09.2026, dort aus demselben Grund bewusst weggelassen).
        // Wie eine Instanz ohne diesen Aufruf trotzdem manuell mit dem
        // nativen Gateway verbunden werden kann (module.json-Deklaration?
        // Konsole?), ist noch offen — ungeklärt lassen ist hier die
        // sicherere Wahl als eine ungetestete Vermutung auszurollen.

        $this->RegisterPropertyBoolean('Active', true);
        // Rein informativer Standort (Raum/Geschoss) — Dietmars Auftrag
        // 01.09.2026, aus MeterHubVirtual übernommen: geteilter Vorschlags-
        // pool über beide Modultypen (siehe GetConfigurationForm()).
        $this->RegisterPropertyString('Location', '');
        $this->RegisterPropertyString('FormLayout', '[]');   // Formular: Aufbau (SUITE.md 3a)
        // Merker: das EIGENE alte benutzerdefinierte Profil wird genau einmal geleert (Checkliste 28, EMS-Ausnahmeregel 09.11.2026)
        $this->RegisterAttributeBoolean('OwnCustomProfileCleared', false);
        $this->RegisterPropertyString('Meter', 'siemens_pac2200');
        // Zähler-Rolle: bestimmt nur die Vorzeichen-Semantik/Dokumentation.
        //   grid        = Netz-/NAP-Zähler (+ Bezug / − Einspeisung)
        //   consumption = Unterzähler/Verbraucher (immer positiv)
        $this->RegisterPropertyString('Role', 'grid');
        // Abrechnungsverbindlicher Zähler am Netzübergabepunkt (geeichtes iMSys/
        // mMSD)? Steuert im Vertrag das Feld `authority` — ein Konsument (EMS,
        // Auswertung) unterscheidet damit den geeichten Netzzähler von einem
        // beliebigen Hilfszähler, wenn zwei am selben Anschluss hängen.
        $this->RegisterPropertyBoolean('BillingGrade', false);
        // Invers-Schalter für die Gesamt-Wirkleistung (Einbaurichtung/Verdrahtung).
        $this->RegisterPropertyBoolean('PowerInvert', false);
        // Energie-Ausgabe in Wh statt kWh (Basiseinheit, konsistent zu W).
        $this->RegisterPropertyBoolean('EnergyUnitWh', false);
        // Float-Wortreihenfolge tauschen (CDAB statt ABCD) — für Geräte/Gateways,
        // die die 16-Bit-Wörter gedreht liefern (z. B. manche Phoenix EEM-XM).
        $this->RegisterPropertyBoolean('WordSwap', false);

        // --- Funktionszuordnung -------------------------------------------
        // Messmodus entscheidet, wie zugeordnet wird:
        //   'combined' = ein Verbraucher über alle Phasen (Netzanschluss, WP …)
        //   'perphase' = drei unabhängige einphasige Verbraucher (je Phase einer)
        $this->RegisterPropertyString('MeasureMode', 'combined');
        $this->RegisterPropertyString('FuncTotal', 'none');
        $this->RegisterPropertyString('FuncTotalLabel', '');
        foreach (['L1', 'L2', 'L3'] as $ph) {
            $this->RegisterPropertyString('Func' . $ph, 'none');
            $this->RegisterPropertyString('FuncLabel' . $ph, '');
        }
        // Zusätzliche, nach der Funktion benannte Sammel-Variablen anlegen.
        $this->RegisterPropertyBoolean('FuncMirrors', false);

        // Eingaben für den Generator „virtueller Zähler" (siehe CreateVirtual).
        $this->RegisterPropertyString('VirtualPartners', '[]');
        $this->RegisterPropertyString('VirtualRole', 'parent');

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitId', 1);
        // Verbindungsweg (SUITE.md 9j, Dietmars Auftrag 18.09.2026): zusätzlich
        // zum bisherigen direkten Weg (fsockopen, s. o.) optional über Symcons
        // natives Modbus-Gateway (eingebauter RS485-Port einer Symbox), und
        // zwar über die Brücken-Instanz MeterHubBridge (BridgeInstanceID) —
        // siehe MHUB_ModbusGatewayClient.
        $this->RegisterPropertyString('ConnectionMode', 'direct');
        $this->RegisterPropertyInteger('BridgeInstanceID', 0);
        $this->RegisterPropertyInteger('GatewayPick', 0); // nur Formular-Eingabe für CreateBridge()

        // Sollwert-Schreibzugriff (blue'Log RPC/Power Control, Dietmars
        // Auftrag 07.09.2026 — Ersatz für die bisher in PHP-Skripten
        // vergrabene "Regelung der blue'Log Master"). Nur bei den beiden
        // WRITABLE_METERS sichtbar/wirksam, aber unbedingt registriert (siehe
        // Begründung beim Meter-Auswahlfeld selbst weiter unten).
        $this->RegisterPropertyString('SetpointMode', 'rel'); // 'rel' (%) oder 'abs' (W)
        $this->RegisterPropertyFloat('TargetSetpoint', 100.0);
        $this->RegisterPropertyString('FailsafeMode', 'hold'); // 'hold' oder 'default'
        $this->RegisterPropertyFloat('FailsafeDefaultPercent', 100.0);
        // Nur RPC: Gültigkeitszeit in Minuten (Register 5006). Power Control
        // hat laut Herstellerdoku keinen vergleichbaren Mechanismus.
        $this->RegisterPropertyInteger('RpcValidTimeMin', 10);

        // Inexogy-Cloud-Zugang. E-Mail/Passwort dienen nur dem einmaligen
        // OAuth-Handshake; das Passwort wird danach geleert. Die Tokens liegen
        // in Attributen (nicht im Formular sichtbar, nie im Klartext-Anzeige).
        $this->RegisterPropertyString('InexogyEmail', '');
        $this->RegisterPropertyString('InexogyPassword', '');
        $this->RegisterPropertyString('InexogyMeterID', '');
        // Lastgang-Nachtrag ins Archiv (10.08.2026, Dietmars Abrechnungszähler).
        $this->RegisterPropertyInteger('InexogyBackfillDays', 7);
        // Automatischer wiederkehrender Nachtrag (27.08.2026, zweimal auf
        // Dietmars Rückfragen hin überarbeitet — siehe
        // DoAutoBackfillInexogyArchive()): die laufende Live-Abfrage
        // (power_total/energy_*) lief schon immer automatisch über
        // FastTimer/SlowTimer, der Archiv-Nachtrag bisher nur auf
        // Knopfdruck. 15 Minuten als Standard-Takt ist genau richtig, weil
        // Inexogys /readings ohnehin nur 15-Minuten-Werte liefert (öfter
        // fragen bringt keine frischeren Daten). InexogyAutoBackfillDays
        // ist KEIN fester Rückblick je Lauf mehr, sondern nur die
        // Obergrenze für den Fall einer größeren Lücke (z. B. nach einem
        // Ausfall) — der Normalfall ermittelt den tatsächlich fehlenden
        // Zeitraum aus dem Archiv selbst.
        $this->RegisterPropertyBoolean('InexogyAutoBackfillEnabled', false);
        $this->RegisterPropertyInteger('InexogyAutoBackfillIntervalMin', 15);
        $this->RegisterPropertyInteger('InexogyAutoBackfillDays', 3);
        $this->RegisterAttributeInteger('InexogyAutoBackfillLastRunTs', 0);
        $this->RegisterAttributeString('InexogyConsumerKey', '');
        $this->RegisterAttributeString('InexogyConsumerSecret', '');
        $this->RegisterAttributeString('InexogyToken', '');
        $this->RegisterAttributeString('InexogyTokenSecret', '');
        // Zuletzt bei Inexogy gefundene Zähler, als Formular-Optionen fertig
        // aufbereitet (JSON-Liste ['caption'=>…,'value'=>…]) — überlebt einen
        // Formular-Reload/-Neuaufbau, anders als die rein ephemere Anzeige direkt
        // nach dem Login (siehe InexogyLogin()/GetConfigurationForm()).
        $this->RegisterAttributeString('InexogyMeterOptions', '');
        // true, solange Inexogy den gespeicherten Zugriffsschlüssel mit HTTP 401
        // ablehnt — nur für die Statuszeile im Formular (InexogyStatusLine()).
        $this->RegisterAttributeBoolean('InexogyAuthRejected', false);
        // Automatische Neuanmeldung bei abgelehntem Zugriffsschlüssel: Passwort
        // wird NICHT in MeterHub gespeichert, sondern bei Bedarf aus einem
        // Tresor (Community-Modul SymconSecrets, SEC_GetSecret) gelesen. Instanz
        // 0 = automatisch erkennen (genau ein Tresor mit passendem Eintrag), sonst
        // bleibt es bei der Meldung und dem manuellen Anmelden.
        // Tresor für die automatische Neuanmeldung verwenden, wenn vorhanden (Standard an) — ohne Auswahl wird er selbst erkannt.
        $this->RegisterPropertyBoolean('SecretsEnabled', true);
        $this->RegisterPropertyInteger('SecretsInstanceID', 0);
        $this->RegisterPropertyString('SecretsRecord', 'Inexogy');
        $this->RegisterPropertyString('SecretsPassField', 'Pass');
        $this->RegisterPropertyString('SecretsUserField', 'User');
        // Zeitpunkt des letzten automatischen Versuchs, Fehlversuche in Folge
        // (für den wachsenden Abstand) und Kurzbericht des letzten Versuchs.
        $this->RegisterAttributeInteger('InexogyAutoReloginLastTs', 0);
        $this->RegisterAttributeInteger('InexogyAutoReloginFails', 0);
        $this->RegisterAttributeString('InexogyAutoReloginInfo', '');
        // Rechenstand der hochgerechneten Energie (letzter Zeitpunkt + Leistung
        // je Energie-Ident), siehe AdvanceCalculatedEnergy(). Der Zählerstand
        // selbst steckt in der Variable, nicht hier.
        $this->RegisterAttributeString('CalcEnergyState', '{}');
        // Zählerschutz (0.26.6): Anzahl in Folge verworfener Rückschritte je
        // Zählervariable — ab COUNTER_RESET_CONFIRM gilt es als Zählertausch.
        $this->RegisterAttributeString('CounterGuard', '{}');
        // Energie-Archiv prüfen/reparieren: Referenzzähler für die Form
        // aufzufüllender Lücken (0 = gleichmäßig) und Prüfzeitraum in Tagen.
        $this->RegisterPropertyInteger('RepairReference', 0);
        $this->RegisterPropertyInteger('RepairDays', 14);
        // Einzelne Rückschritte mitreparieren? Standard ja (bei normalen
        // Zählern ein Messfehler). Bei Inexogy entstehen sie durch gemischte
        // Live-/Viertelstundenwerte — dort kann der tiefere Punkt der
        // offizielle Wert sein (Dietmars Entscheidung 12.09.2026: nur die
        // Lücke reparieren).
        $this->RegisterPropertyBoolean('RepairBackward', true);
        // Zeitraum für „Live-Zwischenwerte bereinigen" (Inexogy, 0.26.9).
        $this->RegisterPropertyInteger('InexogyCleanupDays', 30);
        // Richtung (0.27.0): für welche PowerInvert-Stellung die Energie-
        // Variablen gerade angeordnet sind ('' = noch nie abgeglichen, 'on'/
        // 'off'), siehe SyncInvertLayout(); Prüfzeitraum der Archiv-Korrektur;
        // zwischengespeicherte Richtungsprüfung (GetDiagnostics()).
        $this->RegisterAttributeString('InvertLayout', '');
        $this->RegisterPropertyInteger('DirectionRepairDays', 60);
        $this->RegisterAttributeString('DirectionDiag', '');
        // Wartende Neuberechnungen der Verdichtung (0.27.7), siehe ReAggregate().
        $this->RegisterAttributeString('ReAggQueue', '[]');
        $this->RegisterPropertyInteger('IntervalFast', 5);
        $this->RegisterPropertyInteger('IntervalSlow', 60);
        // Archiv-Verdichtung, konfigurierbar statt fest im Code (Dietmars
        // Einwand 31.08.2026: "ich bin nicht der einzigste Nutzer, andere
        // haben vielleicht andere Vorstellungen" — seine eigenen Werte
        // bleiben nur noch die VORBELEGUNG, siehe CompactionPlan()). Doppelt
        // für Leistung und Energie (Dietmars Ergänzung, noch am selben Tag:
        // „wir müssen zwischen Leistungswerten und Energiewerten
        // unterscheiden" — unabhängig vom Update-Takt können beide ganz
        // andere Aufbewahrungs-Anforderungen haben).
        foreach (['Power', 'Energy'] as $kind) {
            // Standard AUS (Store-Review-Checkliste 9k: archivbezogene Schalter starten aus, der Nutzer
            // stimmt bewusst zu). Bestehende Instanzen behalten ihren gespeicherten Wert (an) — und
            // selbst wo ein Wert fehlen sollte, fasst ein ausgeschalteter Schalter das Archiv nicht an,
            // siehe CompactionPlan(): zurückgesetzt wird nur beim Wechsel von an auf aus.
            $this->RegisterPropertyBoolean('AutoCompaction' . $kind, false);
            $this->RegisterAttributeBoolean('CompactionApplied' . $kind, false);
            $this->RegisterPropertyInteger('CompactDirect' . $kind, 0);        // direkt: 1×/Minute
            $this->RegisterPropertyInteger('CompactStage2Months' . $kind, 1);
            $this->RegisterPropertyInteger('CompactStage2Type' . $kind, 1);    // nach Stufe 2: 1×/5 Min
            $this->RegisterPropertyInteger('CompactStage3Months' . $kind, 12);
            $this->RegisterPropertyInteger('CompactStage3Type' . $kind, 2);    // nach Stufe 3: 1×/Stunde
        }

        // Optionale Gruppen aller Treiber registrieren (nicht nur des aktuell
        // gewählten) — der endgültige Meter-Wert wird oft erst nach Create()
        // gesetzt (z. B. vom Discovery-Modul). Ungenutzte Properties schaden
        // nicht. (Gleiches Muster wie InverterHub.)
        $allProps = [];
        foreach (self::DRIVERS as $driverClass) {
            $drv = new $driverClass();
            foreach ($drv->getOptionalGroups() as $propName => $group) {
                if (!array_key_exists($propName, $allProps)) {
                    $allProps[$propName] = true;
                }
            }
        }
        foreach ($allProps as $name => $default) {
            $this->RegisterPropertyBoolean($name, $default);
        }

        $this->RegisterTimer('FastTimer', 0, 'MHUB_ReadFast($_IPS[\'TARGET\']);');
        $this->RegisterTimer('SlowTimer', 0, 'MHUB_ReadSlow($_IPS[\'TARGET\']);');

        // Formular-Konvention (SUITE.md "Einheitliche Formular-Optik") — bei
        // MeterHub/MeterHubDiscovery bisher NICHT umgesetzt (siehe CLAUDE.md,
        // "nicht eilig, bei Gelegenheit nachziehen"). Nachgezogen 01.09.2026
        // im Zuge von Dietmars Manifest-Abgleich-Auftrag, Muster 1:1 aus
        // MeterHubVirtual übernommen (dort seit 31.08.2026 Referenz).
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('ForumHintGone', false);
        // Zweck-Einführung (Dietmars Auftrag 01.09.2026, ausgelöst durch
        // Sepps Rückmeldung — siehe MeterHubVirtual::PurposeIntro() für die
        // volle Herleitung). Einmalig dismissible, nicht pro Version.
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
    }

    // Einphasige Zähler: der Messmodus „dreiphasig/je Phase" ergibt dort keinen Sinn (Forum-Feedback 20.09.2026).
    private const SINGLE_PHASE_METERS = ['eastron_sdm120', 'eastron_sdm220', 'eastron_sdm230'];
    private const NEWS_VERSION = '0.35.0';
    // Brücken-Modul (MeterHubBridge/module.json) — Gegenstelle des Verbindungswegs „Symbox-Gateway".
    private const BRIDGE_GUID = '{39E4438F-FE02-4025-973E-318355C6DC82}';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-tester-gesucht-nrg-stack-meterhub-energiezaehler-ein-modbus-tcp-modul-fuer-siemens-janitza-eastron-shelly-go-e-meteocontrol-bluelog-u-a-discovery-virtuelle-zaehler/144395';
    private const LICENSE_URL = 'https://github.com/DG65/NRGMeterHub/blob/ems-integration/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';
    // Unveränderter Symcon-Standardname (= module.json 'name') einer nie umbenannten
    // Instanz — Marker für FunctionAssignments(), ob der Instanzname aussagekräftig ist.
    private const DEFAULT_INSTANCE_NAME = 'MeterHub';

    /** Siehe MeterHubVirtual::PurposeIntro() für die volle Herleitung — steht ganz vorn, noch vor dem News-Panel. */
    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'MeterHub reads real energy meters from various manufacturers directly — via Modbus TCP or cloud API — and makes power, import and feed-in available as normal Symcon variables.'],
                ['type' => 'Label', 'caption' => 'The benefit: real measurements instead of estimates — as a basis for dashboards, load management, an energy management system (EMS), or simply to see what a device really consumes.'],
                ['type' => 'Label', 'caption' => 'Is the right meter missing from the list, or do you want to set up several at once? MeterHubDiscovery searches the local network for them. If you would rather calculate a new number from existing values (e.g. the remainder of a house connection), MeterHubVirtual is the right module.'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MHUB_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->PropagateDismiss('PurposeIntro');
    }

    /** Aufgeklappt und pro Version einmalig bestätigbar — Formular-Konvention, siehe MeterHubVirtual::NewsBanner(). */
    private function NewsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  New in this version',
            'items' => [
                ['type' => 'Label', 'caption' => '• 🌐 New: MeterHub is now available in German and English. Texts follow the Symcon language setting: the form is translated by the console, messages are shown in the set language, and variable names are set in the language that is active when you click "Apply". Identifiers, properties and functions are unchanged — nothing is lost on existing instances.'],
                ['type' => 'Label', 'caption' => '• 📈 Important: MeterHub no longer switches archiving (logging) on by itself. Archiving and history are yours. If variables are not archived, a notice with a button "Enable archiving for these variables" appears at the top of the form. Archiving that is already running stays as it is and is never switched off. New instances only archive after you click; archive compaction and the Inexogy backfill only work on archived variables.'],
                ['type' => 'Label', 'caption' => '• 🎛️ Display: the profile (unit, decimal places) now comes as the variable\'s default presentation, no longer as a custom profile — that belongs to you. A custom profile of its own (NRG.*, MHB.*) that MeterHub set earlier is removed by MeterHub exactly once per instance (marker); the default presentation shows the same thing, and if you enter one yourself later, it stays. A different profile you chose stays as well. "Energy in Wh" switches the default profile.'],
                ['type' => 'Label', 'caption' => '• 🗂️ Tidied up: the variables now sit directly under the instance instead of in subcategories (totals, energy meters …). Categories under instances are not intended in Symcon, and the visualization does not know them. MeterHub tidies existing instances up by itself on the next "Apply": the variables keep their ID, so archive, links and scripts are not affected. Only the path in the object tree is shorter. If you put something into one of these categories yourself, it stays together with its contents.'],
                ['type' => 'Label', 'caption' => '• 🪟 New: choose the form layout yourself. In the new panel "Form: layout" (at the bottom, before the forum notice) you decide per section whether it is expanded, collapsed or hidden when opened, and at which position it sits. Symcon does not remember expanding and collapsing by itself, so the form looks the way you need it every time. Hidden sections keep their settings and keep working. No tick means "default"; "Reset to default" restores everything. Takes effect after "Apply" the next time the form is opened.'],
                ['type' => 'Label', 'caption' => '• 🗄️ Archive compaction: new instances start with "off" so you consent deliberately. Existing instances keep their setting (on) unchanged. The archive is now only reset when switching off (from on to off), no longer on every "Apply" of a switched-off setting.'],
                ['type' => 'Label', 'caption' => '• 🗝️ Inexogy: optional automatic re-login. If the access token becomes invalid, MeterHub can log in again by itself — the password is kept in a vault for this (community module "SymconSecrets"), never in MeterHub. MeterHub detects by itself whether a vault with the password is present; the line below it in "Cloud access" shows what applies. If there is none, nothing changes. "Log in" then also works without a typed-in password.'],
                ['type' => 'Label', 'caption' => '• 🔐 Inexogy: if Inexogy rejects the stored access token (HTTP 401 — according to Inexogy it can become invalid), the status line in "Cloud access" now says so clearly ("⚠️ … log in again") instead of a contradictory "✅ logged in, query failed". Then enter e-mail/password once and click "Log in and fetch meters"; the backfill fills the gap by itself afterwards. The meter list after login also stays in place instead of disappearing again on apply.'],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MHUB_AckNews($id);'],
            ],
        ];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
        $this->PropagateDismiss('News', self::NEWS_VERSION);
    }

    /** Symcon-Forum-Hinweis — einmalig dismissible, kein Versionsbezug, siehe MeterHubVirtual::ForumHint(). */
    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback in the Symcon forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'Feedback, especially on the 🔧/🧪 marked meter types, is explicitly welcome in the community thread.'],
                ['type' => 'Button', 'caption' => 'Go to the forum thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => 'MHUB_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint()
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
        $this->PropagateDismiss('ForumHint');
    }

    /**
     * Ausblenden von "Wozu dieses Modul?"/"Was ist Neu?"/Forum-Hinweis über
     * alle Geschwister-Instanzen dieses Moduls teilen (Dietmar 14.09.2026,
     * SUITE.md "Ausblenden über mehrere Instanzen desselben Moduls teilen").
     * Anlass: 54 MeterHub-Instanzen am Solarpark — ohne das hier müsste
     * derselbe Hinweis 54-mal einzeln weggeklickt werden. Ruft bei jeder
     * Geschwister-Instanz NUR den reinen Übernahme-Schritt auf
     * (AdoptDismissState), nicht erneut die volle Ack-Methode — dadurch
     * kein Ping-Pong möglich, ganz ohne Prozessmerker: AdoptDismissState()
     * propagiert selbst nie weiter.
     */
    private function PropagateDismiss(string $what, string $value = ''): void
    {
        foreach (IPS_GetInstanceListByModuleID(self::GUID_METER) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                MHUB_AdoptDismissState($sib, $what, $value);
            } catch (\Throwable $e) {
                // Eine Geschwister-Instanz mitten im Reload/Löschen darf das
                // Ausblenden der aufrufenden Instanz nicht mitreißen — @ hält
                // Fatals bekanntlich nicht auf (siehe CLAUDE.md, MigrationsHub-
                // Brücke).
            }
        }
    }

    /** Reiner Übernahme-Schritt für eine Geschwister-Instanz — siehe PropagateDismiss(). Kein $id-Parameter nötig, da nur intern von MHUB_ selbst aufgerufen (fixe Arität ab hier). */
    public function AdoptDismissState(string $what, string $value)
    {
        switch ($what) {
            case 'PurposeIntro':
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
                $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
                break;
            case 'ForumHint':
                $this->WriteAttributeBoolean('ForumHintGone', true);
                $this->UpdateFormField('ForumHintPanel', 'visible', false);
                break;
            case 'News':
                $this->WriteAttributeString('SeenNews', $value);
                $this->UpdateFormField('NewsPanel', 'visible', false);
                break;
        }
    }

    /** Für Geschwister-Instanzen, die beim erstmaligen Kontakt den Ausblenden-Stand übernehmen wollen — siehe AdoptDismissFromSibling(). */
    public function GetDismissState(): array
    {
        return [
            'purposeIntroGone' => $this->ReadAttributeBoolean('PurposeIntroGone'),
            'forumHintGone'    => $this->ReadAttributeBoolean('ForumHintGone'),
            'seenNews'         => $this->ReadAttributeString('SeenNews'),
        ];
    }

    /**
     * Gegenrichtung zu PropagateDismiss(): eine neu angelegte Instanz sieht
     * beim ersten ApplyChanges() bei einer beliebigen Geschwister-Instanz
     * nach und übernimmt deren Stand, statt "Wozu dieses Modul?"/"Was ist
     * Neu?"/Forum-Hinweis erneut zu zeigen, obwohl der Nutzer sie an anderer
     * Stelle schon bestätigt hat. Zieht nur vor (false→true, ältere→neuere
     * News-Version), überschreibt nie einen schon weiter fortgeschrittenen
     * eigenen Stand.
     */
    private function AdoptDismissFromSibling(): void
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone') && $this->ReadAttributeBoolean('ForumHintGone')
            && $this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return;
        }
        foreach (IPS_GetInstanceListByModuleID(self::GUID_METER) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                $state = MHUB_GetDismissState($sib);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($state)) {
                continue;
            }
            if (!$this->ReadAttributeBoolean('PurposeIntroGone') && !empty($state['purposeIntroGone'])) {
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
            }
            if (!$this->ReadAttributeBoolean('ForumHintGone') && !empty($state['forumHintGone'])) {
                $this->WriteAttributeBoolean('ForumHintGone', true);
            }
            if ($this->ReadAttributeString('SeenNews') !== self::NEWS_VERSION && ($state['seenNews'] ?? '') === self::NEWS_VERSION) {
                $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
            }
            break;
        }
    }

    /**
     * Lizenz-/Unterstützungs-Hinweis — Dietmars Auftrag 01.09.2026, Wortlaut
     * ("Variante A") verbundweit als SUITE.md-Konvention festgehalten, damit
     * alle NRG-Stack-Module denselben Text verwenden. Anders als der
     * Forum-Hinweis bewusst NICHT wegklickbar — eine Lizenz ist kein
     * einmaliger Hinweis, der nach dem ersten Lesen verschwinden sollte.
     * Eingeklappt by default (kein `ExpansionPanel`-`name` nötig, da nichts
     * per UpdateFormField() geschlossen wird), aber immer im Formular
     * vorhanden, ganz unten nach dem Forum-Hinweis.
     */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of real enthusiasm for my own energy system — and a few evenings spent typing. Still: hobby or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private, non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or license questions? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Like the module and want to leave something anyway? A small donation is appreciated — entirely voluntary, nothing expected in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /**
     * Übernimmt einen gewählten Standort-Vorschlag ins Freitext-Feld
     * "Location" — siehe MeterHubVirtual::ApplyLocationPreset(), identisches
     * Muster. Keine echte eigene Property: der Vorschlag ist nur ein
     * Schnellausfüller, gespeichert wird ausschließlich "Location".
     */
    public function ApplyLocationPreset(string $preset)
    {
        if (trim($preset) !== '') {
            $this->UpdateFormField('Location', 'value', $preset);
        }
    }

    /**
     * Benennt die Instanz um — siehe MeterHubVirtual::RenameInstance(),
     * identisches Muster (Dietmars Fund: Symcon zeigt kein natives
     * Namensfeld). `IPS_SetName()` ist KEINE registrierte Property, wirkt
     * sofort per `onChange`.
     */
    public function RenameInstance(string $name)
    {
        $name = trim($name);
        if ($name !== '' && $name !== IPS_GetName($this->InstanceID)) {
            IPS_SetName($this->InstanceID, $name);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();
        // Vor RegisterVariables(): die tauscht danach Namen/Positionen passend
        // zu den neuen Idents mit.
        $this->SyncInvertLayout();
        // Richtungsprüfung neu rechnen lassen: nach Übernehmen (z. B. PowerInvert
        // umgeschaltet) und nach einem Modul-Update wäre der Zwischenspeicher
        // sonst bis zu 30 min veraltet (live 12.09.2026: nach dem Update auf
        // 0.27.3 lieferten beide Anlagen noch das Ergebnis des alten Codes).
        $this->WriteAttributeString('DirectionDiag', '');
        $this->RegisterVariables();
        // Bereitschaft: Modbus-Zähler brauchen eine IP, Cloud-Zähler ein
        // gültiges Zugriffs-Token samt gewählter Zähler-UID.
        $isCloud = in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true);
        // Symbox-Gateway: Host/Port entfallen, bereit ist die Instanz mit einer
        // gewählten Brücke. Ob die Brücke selbst mit einem Gateway verbunden ist,
        // ändert sich unabhängig von dieser Instanz — dann scheitert der
        // Lesezyklus mit Status 201 und erklärendem Text, die Timer laufen.
        $ready   = $isCloud
            ? ($this->ReadAttributeString('InexogyToken') !== '' && $this->ReadPropertyString('InexogyMeterID') !== '')
            : ($this->ReadPropertyString('ConnectionMode') === 'gateway'
                ? $this->ReadPropertyInteger('BridgeInstanceID') > 0
                : $this->ReadPropertyString('Host') !== '');
        if (!$this->ReadPropertyBoolean('Active') || !$ready) {
            // Ausfallverhalten "Default-Sollwert": beim Deaktivieren (nicht
            // beim erstmaligen Anlegen ohne Host — dort gibt es nichts zu
            // schreiben) einmalig aktiv auf den Rückfallwert schreiben, statt
            // bis zu 10 Minuten auf das blue'Log-eigene Ablaufen der
            // Gültigkeitszeit zu warten. "Letzten Sollwert halten" tut hier
            // bewusst nichts weiter.
            $driver = $this->GetDriver();
            if ($ready && $driver instanceof MHUB_WritableMeterDriverInterface && $this->FailsafeMode() === 'default') {
                $driver->writeFailsafe($this->GetTransport(), $this);
            }
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            return;
        }

        $this->SetTimerInterval('FastTimer', $this->ReadPropertyInteger('IntervalFast') * 1000);
        $this->SetTimerInterval('SlowTimer', $this->ReadPropertyInteger('IntervalSlow') * 1000);
        $this->SetStatus(102);
    }

    /**
     * Store-Checkliste 9c: Während Symcon ein Modul neu lädt (Update,
     * Neustart), feuern Timer und Aufrufe anderer Module noch in die Instanz —
     * jeder SDK-Aufruf meldet dann „InstanceInterface is not available“ (live
     * 13.09.2026: Dutzende Warnungen je Update aus ReadFast/GetFunctions).
     * Einstiegspunkte fragen das zuerst still ab. function_exists nur für die
     * Prüfstände, in Symcon gibt es beide Funktionen immer.
     */
    private function InstanceReady(): bool
    {
        if (function_exists('IPS_GetKernelRunlevel') && IPS_GetKernelRunlevel() !== KR_READY) {
            return false;
        }
        if (function_exists('IPS_InstanceExists') && !IPS_InstanceExists($this->InstanceID)) {
            return false;
        }
        error_clear_last();
        @$this->ReadPropertyBoolean('Active');
        $e = error_get_last();
        return $e === null || !str_contains((string)($e['message'] ?? ''), 'InstanceInterface');
    }

    public function ReadFast()
    {
        if (!$this->InstanceReady()) {
            return;
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $driver = $this->GetDriver();
        $mb     = $this->GetTransport();
        $ok     = $driver->readFast($mb, $this);
        // Schreibzyklus direkt im Anschluss an den Lesezyklus — kein
        // eigener Timer nötig. Läuft bei JEDEM Takt, auch ohne geänderten
        // Zielwert (siehe Klassenkommentar MHUB_BlueLogRpcDriver: das
        // Nachschreiben IST das Sicherheitsnetz gegen das Ablaufen der
        // Gültigkeitszeit am blue'Log).
        if ($driver instanceof MHUB_WritableMeterDriverInterface) {
            $driver->writeSetpoint($mb, $this);
            $this->FlushTransportNotices($mb);
        }
        if ($ok && $driver instanceof MHUB_CalculatedEnergyDriverInterface) {
            $this->AdvanceCalculatedEnergy($driver->calculatedEnergy());
        }
        // Eine Verbindung je Zyklus: Lesen UND Sollwert-Schreiben liefen über
        // denselben Client, jetzt einmal schließen (siehe MHUB_ModbusTcpClient).
        if (method_exists($mb, 'close')) {
            $mb->close();
        }
        $this->SetStatus($ok ? 102 : 201);
        $this->UpdateMirrors();
    }

    /** Hinweise der Transportklasse (Bibliotheksklasse ohne eigenes LogMessage) ins Protokoll dieser Instanz übernehmen. */
    private function FlushTransportNotices($mb): void
    {
        if (is_object($mb) && method_exists($mb, 'takeNotices')) {
            foreach ($mb->takeNotices() as $n) {
                $this->LogMessage((string)$n, KL_WARNING);
            }
        }
    }

    public function ReadSlow()
    {
        if (!$this->InstanceReady()) {
            return;
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $mb = $this->GetTransport();
        $this->GetDriver()->readSlow($mb, $this);
        if (method_exists($mb, 'close')) {
            $mb->close();
        }
        $this->UpdateMirrors();
        $this->MaybeAutoBackfillInexogy();
        // Richtungsprüfung (0.27.0): höchstens alle 30 min neu gerechnet,
        // ein neuer Befund landet einmal im Log — darf das Lesen nie stören.
        try {
            $this->GetDiagnostics();
        } catch (\Throwable $e) {
            $this->SendDebug('Richtungsprüfung', $e->getMessage(), 0);
        }
        try {
            $this->ProcessReAggQueue();
        } catch (\Throwable $e) {
            $this->SendDebug('Verdichtung', $e->getMessage(), 0);
        }
    }

    /**
     * Energie aus der Leistung hochrechnen (Trapez: Mittel der letzten zwei
     * Messwerte × Zeit dazwischen) und auf den Zählerstand der Variable
     * aufaddieren. Zeitbasis ist `VariableUpdated` der Leistungsvariable —
     * ein Takt, in dem der Treiber keinen gültigen Wert geschrieben hat (NaN
     * am Gerät), ändert den Zeitstempel nicht und zählt deshalb nicht. Die
     * Summe aller Zeitabschnitte ist exakt die tatsächlich überdeckte Zeit,
     * Takt-Schwankungen heben sich auf.
     */
    private function AdvanceCalculatedEnergy(array $map): void
    {
        $state = json_decode((string)$this->ReadAttributeString('CalcEnergyState'), true);
        $state = is_array($state) ? $state : [];
        // Längere Lücken (Gerät nicht erreichbar, Symcon-Neustart, Instanz
        // deaktiviert) werden NICHT mit einem veralteten Wert überbrückt —
        // lieber etwas zu wenig als erfundener Ertrag.
        $maxGap = max(300, 5 * max(1, $this->ReadPropertyInteger('IntervalFast')));
        $invert = $this->ReadPropertyBoolean('PowerInvert');
        foreach ($map as $eIdent => $pIdent) {
            $pid = $this->FindVarByIdent($pIdent);
            $eid = $this->FindVarByIdent($this->EnergyIdentForInvert($eIdent));
            if (!$pid || !$eid) {
                continue;
            }
            $p = (float)GetValue($pid);
            if ($pIdent === 'power_total' && $invert) {
                $p = -$p; // SetVarFloat() hat invertiert — für die Rechnung zurück
            }
            $t = (int)(IPS_GetVariable($pid)['VariableUpdated'] ?? 0);
            [$addKWh, $state[$eIdent]] = self::CalcEnergyStep($state[$eIdent] ?? null, $t, $p, $maxGap);
            if ($addKWh > 0) {
                $factor = $this->ReadPropertyBoolean('EnergyUnitWh') ? 1000.0 : 1.0;
                SetValueFloat($eid, (float)GetValue($eid) + $addKWh * $factor);
            }
        }
        $this->WriteAttributeString('CalcEnergyState', (string)json_encode($state));
    }

    /**
     * Plausibilitätssperre für CalcEnergyStep() (Pendant zu MeterHubVirtual::
     * PlausiblePower(), Solarpark-Fund 15.09.2026: ein einzelner riesiger-aber-
     * endlicher Fehl-Lesewert, z. B. eine verunglückte Modbus-Dekodierung bei
     * einem Verbindungsaussetzer, blieb bisher dauerhaft im kumulativen
     * Ertragszähler stehen — is_finite() fängt nur NaN/Unendlich).
     *
     * Der Median-Vergleich der virtuellen Zähler passt hier nicht: ein
     * Einzelzähler hat im selben Durchlauf keine Vergleichsgeräte. Stattdessen
     * (1) derselbe generische Absolutdeckel von 1 GW, den nie ein realer
     * Messpunkt erreicht, und (2) ein Sprungvergleich gegen den letzten
     * akzeptierten Wert derselben lückenlosen Messreihe. Bewusst KEIN fester
     * Watt-Grenzwert (Balkonanlage und Solarpark haben andere Maßstäbe) — der
     * Faktor ist relativ. Ein Sprung, der über CALC_JUMP_CONFIRM Takte in Folge
     * ungefähr gleich hoch bleibt, ist keine Fehllesung, sondern ein echter
     * Stufenwechsel (z. B. zugeschaltete Gruppe) und wird übernommen.
     *
     * Der Faktor ist mit 20 bewusst strenger als der 200 der virtuellen Zähler
     * (dort ein Vergleich gegen Geräte derselben Gruppe): Eine Fehlablehnung
     * kostet dank Bestätigung nur die Energie von höchstens zwei Takten, eine
     * durchgelassene Fehllesung dagegen bleibt dauerhaft im Zählerstand. Bei
     * 3,6 MW Grundlast hätte 200 noch Werte bis 720 MW durchgelassen.
     */
    private const CALC_POWER_CEILING_W = 1_000_000_000.0;
    private const CALC_JUMP_FACTOR = 20.0;
    private const CALC_JUMP_CONFIRM = 3;

    /**
     * Ein Rechenschritt, frei von Symcon-Aufrufen (Prüfstand). Rückgabe
     * [kWh dazu, neuer Rechenstand]. Negative Leistung (Eigenverbrauch der
     * Wechselrichter nachts) zählt als 0 — ein Ertragszähler läuft nie
     * rückwärts. Ungültige und unplausible Werte ändern nichts; während eines
     * abgelehnten Werts bleibt der Zeitstempel des letzten akzeptierten stehen,
     * sodass der nächste gültige Wert die Zeit dazwischen mit beiden
     * Nachbarwerten überbrückt.
     *
     * Grenze der Sprungsperre: Nach einer Lücke über $maxGap gibt es keinen
     * Vergleichswert mehr — der neue Ausgangspunkt wird nur gegen den
     * Absolutdeckel geprüft.
     */
    private static function CalcEnergyStep(?array $prev, int $t, float $p, int $maxGap): array
    {
        if (!is_finite($p) || $t <= 0) {
            return [0.0, $prev];
        }
        $p = max(0.0, $p);
        if ($p > self::CALC_POWER_CEILING_W) {
            return [0.0, $prev];
        }
        if ($prev === null || !isset($prev['t'], $prev['p'])) {
            return [0.0, ['t' => $t, 'p' => $p]];
        }
        $dt = $t - (int)$prev['t'];
        if ($dt <= 0) {
            return [0.0, $prev];
        }
        if ($dt > $maxGap) {
            return [0.0, ['t' => $t, 'p' => $p]];
        }
        $base = (float)$prev['p'];
        if ($base > 1.0 && $p > $base * self::CALC_JUMP_FACTOR) {
            $rp = (float)($prev['rp'] ?? 0.0);
            $rn = ($rp > 0.0 && $p >= $rp / 2.0 && $p <= $rp * 2.0) ? (int)($prev['rn'] ?? 0) + 1 : 1;
            if ($rn >= self::CALC_JUMP_CONFIRM) {
                return [0.0, ['t' => $t, 'p' => $p]];
            }
            return [0.0, ['t' => (int)$prev['t'], 'p' => $base, 'rp' => $p, 'rn' => $rn]];
        }
        return [(((float)$prev['p'] + $p) / 2.0) * $dt / 3600000.0, ['t' => $t, 'p' => $p]];
    }

    /**
     * Formular-Knopf „Verbindung testen / Daten sofort lesen" (Verbund-
     * Konvention „Sichtbare Rückmeldung bei jeder Aktion", 20.08.2026,
     * Muster 1 — Rückgabetext statt stillem Aufruf). Bewusst OHNE den
     * `Active`-Guard von ReadFast()/ReadSlow(): ein manueller Verbindungstest
     * soll gerade auch bei einer neu angelegten, noch inaktiven Instanz
     * funktionieren — vorher hatte der Knopf dort schlicht nichts getan (und
     * das auch noch ohne jede Rückmeldung, doppelt irreführend).
     */
    public function TestConnection(): string
    {
        $mb = $this->GetTransport();
        $ok = $this->GetDriver()->readFast($mb, $this);
        $this->SetStatus($ok ? 102 : 201);
        $this->GetDriver()->readSlow($mb, $this);
        if (method_exists($mb, 'close')) {
            $mb->close();
        }
        $this->UpdateMirrors();
        if ($ok) {
            return MHUB_Text::t('✅ Connection successful, values updated (%s).', date('H:i:s'));
        }
        return $this->ReadPropertyString('ConnectionMode') === 'gateway'
            ? MHUB_Text::t('❌ Connection failed — %s', $this->BridgeErrorText())
            : MHUB_Text::t('❌ Connection failed — check host/port/unit ID/meter type.');
    }

    /**
     * Öffentliche Schnittstelle für Skripte/EMS — setzt den Zielwert und
     * schreibt ihn sofort, statt auf den nächsten FastTimer-Takt zu warten.
     * `IPS_SetProperty` + `IPS_ApplyChanges` auf die eigene Instanz ist ein
     * an dieser Stelle bereits verwendetes, sicheres Muster (siehe
     * InexogyLogin() — Passwort-Löschung nach Handshake).
     *
     * MeterHub berechnet den Wert NICHT selbst (z. B. keine kapazitäts-
     * gewichtete Aggregation mehrerer Quellwerte) — das bleibt bewusst
     * außerhalb, siehe Klassenkommentar MHUB_BlueLogRpcDriver.
     */
    public function SetBlueLogTarget(float $value): string
    {
        $driver = $this->GetDriver();
        if (!($driver instanceof MHUB_WritableMeterDriverInterface)) {
            return MHUB_Text::t('❌ This meter type does not support setpoint write access.');
        }
        IPS_SetProperty($this->InstanceID, 'TargetSetpoint', $value);
        IPS_ApplyChanges($this->InstanceID);
        if (!$this->ReadPropertyBoolean('Active')) {
            return MHUB_Text::t('⚠️ Target value saved, but the instance is inactive — nothing is written.');
        }
        $mb = $this->GetTransport();
        $driver->writeSetpoint($mb, $this);
        $this->FlushTransportNotices($mb);
        return MHUB_Text::t('✅ Target value %s (%s) set and written immediately.', $value, $this->SetpointMode() === 'abs' ? 'W' : '%');
    }

    // -----------------------------------------------------------------------
    // Brücke zu MeterHubVirtual
    //
    // Ein virtueller Zähler entsteht fast immer aus zwei, drei echten Zählern,
    // die man ohnehin gerade vor sich hat. Ihn von hier aus anzulegen erspart
    // den Umweg über eine leere Instanz, in die man die Variablen-IDs von Hand
    // zusammensucht. Angelegt wird eine EIGENE Instanz — dieses Modul rechnet
    // weiterhin nichts, es füllt nur deren Verdrahtung vor.
    // -----------------------------------------------------------------------

    private const GUID_VIRTUAL = '{ADF18291-2E60-4354-92F5-B96863C127C8}';
    private const GUID_METER   = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';

    /** Variable eines MeterHub-Geräts anhand ihres Idents (liegt direkt unter der Instanz, in älteren Versionen noch in Kategorien). */
    private function MeterVarID(int $instanceID, string $ident): int
    {
        $stack = [$instanceID];
        while ($stack) {
            foreach (IPS_GetChildrenIDs((int)array_pop($stack)) as $cid) {
                $o = IPS_GetObject($cid);
                if ($o['ObjectIdent'] === $ident && $o['ObjectType'] === 2) {
                    return $cid;
                }
                if ($o['ObjectType'] === 0) {
                    $stack[] = $cid;
                }
            }
        }
        return 0;
    }

    /**
     * In welchen virtuellen Zählern taucht dieser Zähler auf?
     * Rückgabe: [instanzID => [Zeilenbezeichnung, …]]
     */
    private function VirtualMemberships(): array
    {
        $mine = [];
        foreach (['power_total', 'energy_import', 'energy_export'] as $ident) {
            $vid = $this->MeterVarID($this->InstanceID, $ident);
            if ($vid > 0) {
                $mine[$vid] = true;
            }
        }
        $out = [];
        if (!$mine) {
            return $out;
        }
        foreach (IPS_GetInstanceListByModuleID(self::GUID_VIRTUAL) as $iid) {
            $rows = json_decode((string)@IPS_GetProperty($iid, 'Nodes'), true);
            foreach (is_array($rows) ? $rows : [] as $r) {
                foreach (['PowerID', 'EnergyImportID', 'EnergyExportID'] as $f) {
                    if (isset($mine[(int)($r[$f] ?? 0)])) {
                        $label = trim((string)($r['Name'] ?? '')) ?: '?';
                        $out[$iid][$label] = $label;
                        break;
                    }
                }
            }
        }
        return array_map('array_values', $out);
    }

    /**
     * Legt eine MeterHubVirtual-Instanz an und füllt ihre Formel vor.
     * $partners: Listeninhalt aus der Maske, $role: 'parent' | 'sibling'.
     *
     * MeterHubVirtual rechnet seit 0.24.0 flach (Instanz = oberste Ebene,
     * jede Zeile ein Term mit Vorzeichen +/−) statt mit einem Baum aus
     * „Kürzel"/„hängt hinter" — deshalb kein Sammelknoten und kein Kürzel
     * mehr nötig. 'parent': dieser Zähler „+", die Partner „−" (Rest = dieser
     * Zähler minus Partner). 'sibling': alle „+" (reine Summe).
     *
     * Geschrieben wird ausschließlich in die NEUE Instanz — an der eigenen
     * Konfiguration ändert der Knopf nichts.
     */
    public function CreateVirtual(string $partners = '[]', string $role = 'parent')
    {
        $say = function (string $m) {
            $this->UpdateFormField('VirtualResult', 'caption', $m);
            $this->UpdateFormField('VirtualResult', 'visible', true);
        };

        $rows = is_array($partners) ? $partners : json_decode((string)$partners, true);
        $ids  = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $iid = (int)(is_array($r) ? ($r['InstanceID'] ?? 0) : $r);
            if ($iid > 0 && $iid !== $this->InstanceID) {
                $ids[$iid] = $iid;
            }
        }
        $ids = array_values($ids);

        if (!$ids) {
            $say(MHUB_Text::t('❌ No further meter is selected. A virtual meter only makes sense for several meters combined — please enter at least a second instance above.'));
            return 0;
        }
        foreach ($ids as $iid) {
            if (!IPS_InstanceExists($iid)) {
                $say(MHUB_Text::t('❌ Instance #%d does not exist (any more).', $iid));
                return 0;
            }
        }

        // Prüfung: Steckt dieser Zähler schon in einem virtuellen Zähler, wäre
        // ein zweiter der Anfang doppelter Buchführung. Dann lieber dort
        // ergänzen — die Struktur bleibt so eindeutig.
        $member = $this->VirtualMemberships();
        if ($member) {
            $list = [];
            foreach ($member as $iid => $labels) {
                $list[] = MHUB_Text::t('"%s" (#%d, as %s)', IPS_GetName($iid), $iid, implode('/', $labels));
            }
            $say(MHUB_Text::t('⚠️ This meter is already part of %s. A second virtual meter with the same device easily leads to double bookkeeping — please add the row there instead of creating a new one here. Nothing was created.', implode(', ', $list)));
            return 0;
        }

        // Zeilen bauen. Die Funktion bleibt am neuen Zähler bewusst auf
        // „keine Zuordnung" (Instanz-Voreinstellung): Würde der virtuelle
        // Zähler dieselbe Funktion belegen wie der echte, erschiene der
        // Verbraucher in Kachel und Sankey doppelt.
        $warn = [];
        $mk = function (int $iid, int $factor) use (&$warn) {
            $p = $this->MeterVarID($iid, 'power_total');
            $i = $this->MeterVarID($iid, 'energy_import');
            $e = $this->MeterVarID($iid, 'energy_export');
            if ($p === 0 && $i === 0) {
                $warn[] = MHUB_Text::t('"%s" delivers neither total power nor import — the row stays without a data point.', IPS_GetName($iid));
            }
            if ((string)@IPS_GetProperty($iid, 'MeasureMode') === 'perphase' && !in_array((string)@IPS_GetProperty($iid, 'Meter'), self::SINGLE_PHASE_METERS, true)) {
                $warn[] = MHUB_Text::t('"%s" measures three separate consumers per phase; the sum over all phases is taken.', IPS_GetName($iid));
            }
            return ['Name' => IPS_GetName($iid), 'Factor' => $factor, 'PowerID' => $p, 'EnergyImportID' => $i, 'EnergyExportID' => $e];
        };

        $nodes   = [$mk($this->InstanceID, 100)];
        $partnerFactor = $role === 'sibling' ? 100 : -100;
        foreach ($ids as $iid) {
            $nodes[] = $mk($iid, $partnerFactor);
        }

        $iid = IPS_CreateInstance(self::GUID_VIRTUAL);
        IPS_SetName($iid, MHUB_Text::t('Virtual meter %s', IPS_GetName($this->InstanceID)));
        IPS_SetParent($iid, IPS_GetObject($this->InstanceID)['ParentID']);
        IPS_SetProperty($iid, 'Active', true);
        IPS_SetProperty($iid, 'Nodes', json_encode($nodes));
        IPS_ApplyChanges($iid);

        $msg = $role === 'sibling'
            ? MHUB_Text::t('✅ Virtual meter #%d created: all %d meters added at equal rank.', $iid, count($nodes))
            : MHUB_Text::t('✅ Virtual meter #%d created: "%s" minus %d subordinate meter(s) — the remainder is output.', $iid, IPS_GetName($this->InstanceID), count($ids));
        $msg .= MHUB_Text::t('
The function is still set to "none" there — deliberately, because otherwise the same consumer would appear twice in the tile and in the Sankey diagram. Typically you set "House consumption": the remainder is then everything not accounted for by the subtracted meters.');
        if ($warn) {
            $msg .= "\n⚠️ " . implode("\n⚠️ ", $warn);
        }
        $say($msg);
        return $iid;
    }

    public function GetConfigurationForm()
    {
        return json_encode($this->BuildForm());
    }

    /**
     * Baut das Formular. Mit $freeOnly nur die Fachpanels (key => Panel, Standardreihenfolge) — dient auch ResetFormLayout().
     *
     * @return array
     */
    private function BuildForm(bool $freeOnly = false)
    {
        // Abgleich mit Geschwister-Instanzen beim Öffnen des Formulars, nicht in ApplyChanges: beim Neuladen der
        // Module sind die anderen Instanzen evtl. noch nicht geladen (Store-Review Tessie, Checkliste 27).
        if (!$freeOnly) {
            $this->AdoptDismissFromSibling();
        }
        $driver = $this->GetDriver();
        $isCloud = in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true);
        $connectionMode = $this->ReadPropertyString('ConnectionMode');

        // Vorschlagsliste für "Standort": geteilter Pool über MeterHub UND
        // MeterHubVirtual (Dietmars Auftrag 01.09.2026) — siehe
        // MeterHubVirtual::GetConfigurationForm() für dieselbe Logik.
        $locationOptions = [['caption' => '— choose a suggestion —', 'value' => '']];
        $seenLocations = [];
        foreach (array_merge(IPS_GetInstanceListByModuleID(self::GUID_METER), IPS_GetInstanceListByModuleID(self::GUID_VIRTUAL)) as $iid) {
            $loc = trim((string)@IPS_GetProperty($iid, 'Location'));
            if ($loc !== '' && !isset($seenLocations[$loc])) {
                $seenLocations[$loc] = true;
            }
        }
        $sortedLocations = array_keys($seenLocations);
        sort($sortedLocations, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($sortedLocations as $loc) {
            $locationOptions[] = ['caption' => $loc, 'value' => $loc];
        }

        // Verbindungspanel je nach Transport: Cloud-Anmeldung (Inexogy) oder
        // Modbus-TCP-Adresse. Bei Cloud sind Host/Port/UnitId gegenstandslos.
        //
        // Beide Feldgruppen stehen IMMER im Formular, nur die Sichtbarkeit
        // wechselt — und zwar sofort beim Umschalten des „Zählertyp"-Felds
        // (OnChangeMeter(), per 'onChange' an der Meter-Auswahl unten), nicht
        // erst nach „Übernehmen". Grund: GetConfigurationForm() läuft nur
        // beim Öffnen der Maske; ein reiner PHP-if/else auf den GESPEICHERTEN
        // Zählertyp hätte beim Umschalten auf „Inexogy" im selben Formular
        // weiterhin das Host-Feld samt IP-Pflichtformat gezeigt — mit nichts
        // Sinnvollem, was man dort eintragen könnte, und „Übernehmen" bliebe
        // blockiert. Das Host-Feld bekommt sein 'validate' deshalb ebenfalls
        // über OnChangeMeter() geleert, nicht nur 'visible' — falls die
        // Regex-Prüfung auch an unsichtbaren Feldern noch greifen sollte.
        // Die bei der letzten Anmeldung gefundenen Zähler aus dem Attribut
        // wiederverwenden, nicht nur den aktuell gewählten Wert — sonst würde ein
        // Formular-Reload (z. B. durch das eigene IPS_ApplyChanges() beim
        // Passwort-Löschen, InexogyLogin() unten) die gerade erst angezeigte
        // Zählerliste wieder verschlucken, bevor der Nutzer wählen konnte.
        $curUID = $this->ReadPropertyString('InexogyMeterID');
        $cachedMeterOpts = json_decode((string)$this->ReadAttributeString('InexogyMeterOptions'), true);
        $cachedMeterOpts = is_array($cachedMeterOpts) ? $cachedMeterOpts : [];
        if ($cachedMeterOpts) {
            $meterOpts = $curUID === '' ? [['caption' => '— please select —', 'value' => '']] : [];
            $meterOpts = array_merge($meterOpts, $cachedMeterOpts);
            if ($curUID !== '' && !in_array($curUID, array_column($cachedMeterOpts, 'value'), true)) {
                $meterOpts[] = ['caption' => $curUID, 'value' => $curUID];
            }
        } else {
            $meterOpts = [['caption' => '— please log in first —', 'value' => '']];
            if ($curUID !== '') {
                $meterOpts[] = ['caption' => $curUID, 'value' => $curUID];
            }
        }
        // Archiv-Wasserstand einmal beim Formularaufbau berechnen (Dietmars
        // Wunsch 27.08.2026: „Zeitstempel des letzten vorhandenen
        // Datensatzes anzeigen"). UpdateInexogyArchiveStatusField() hält das
        // danach bei jedem tatsächlichen Nachtrag aktuell — hier nur der
        // Anfangszustand beim Öffnen der Maske.
        $archiveStatusCaption = '';
        $archiveStatusVisible = false;
        if ($isCloud) {
            [$watermark, $watermarkErr] = $this->InexogyArchiveWatermark();
            if ($watermarkErr === null && $watermark > 0) {
                $ageMin = (int) round((time() - $watermark) / 60);
                $archiveStatusCaption = MHUB_Text::t('📊 Archive load profile complete up to %s (%d min ago).', date('d.m.Y H:i', $watermark), $ageMin);
                $archiveStatusVisible = true;
            }
        }
        $connectionItems = [
            ['type' => 'Label', 'name' => 'InexogyIntro', 'visible' => $isCloud, 'caption' => '🔐 Login to Inexogy (formerly Discovergy). Enter the e-mail and password of your my.inexogy.com account, apply, then "Log in". The password is used only once for the login and is deleted automatically afterwards — only access tokens are stored (not in plain text).'],
            ['type' => 'Label', 'name' => 'InexogyStatusLine', 'visible' => $isCloud, 'caption' => $isCloud ? $this->InexogyStatusLine() : ''],
            ['type' => 'ValidationTextBox', 'name' => 'InexogyEmail', 'visible' => $isCloud, 'caption' => 'E-mail (Inexogy account)'],
            ['type' => 'PasswordTextBox', 'name' => 'InexogyPassword', 'visible' => $isCloud, 'caption' => 'Password (deleted after login)'],
            ['type' => 'Button', 'name' => 'InexogyLoginButton', 'visible' => $isCloud, 'caption' => '🔑  Log in and fetch meters', 'onClick' => 'MHUB_InexogyLogin($id);'],
            ['type' => 'Label', 'name' => 'InexogyResult', 'caption' => '', 'visible' => false],
            ['type' => 'Label', 'name' => 'SecretsIntro', 'visible' => $isCloud, 'caption' => '🗝️ Automatic re-login (optional): if the Inexogy access token becomes invalid, MeterHub can log in again by itself. The password for this is NOT kept in MeterHub but in a vault (community module "SymconSecrets", entry with fields for e-mail and password). MeterHub detects by itself whether such a vault with the password is present — if not, the normal way applies: log in again by hand. The line below shows what currently applies.'],
            ['type' => 'CheckBox', 'name' => 'SecretsEnabled', 'visible' => $isCloud, 'caption' => 'Use the vault for automatic re-login if present', 'onChange' => 'MHUB_OnChangeSecrets($id, $SecretsEnabled, $SecretsInstanceID, $SecretsRecord, $SecretsPassField);'],
            ['type' => 'SelectInstance', 'name' => 'SecretsInstanceID', 'visible' => $isCloud, 'caption' => 'Vault (SymconSecrets) — empty = detect automatically', 'onChange' => 'MHUB_OnChangeSecrets($id, $SecretsEnabled, $SecretsInstanceID, $SecretsRecord, $SecretsPassField);'],
            ['type' => 'ValidationTextBox', 'name' => 'SecretsRecord', 'visible' => $isCloud, 'caption' => 'Entry in the vault', 'onChange' => 'MHUB_OnChangeSecrets($id, $SecretsEnabled, $SecretsInstanceID, $SecretsRecord, $SecretsPassField);'],
            ['type' => 'ValidationTextBox', 'name' => 'SecretsPassField', 'visible' => $isCloud, 'caption' => 'Field with the password', 'onChange' => 'MHUB_OnChangeSecrets($id, $SecretsEnabled, $SecretsInstanceID, $SecretsRecord, $SecretsPassField);'],
            ['type' => 'ValidationTextBox', 'name' => 'SecretsUserField', 'visible' => $isCloud, 'caption' => 'Field with the e-mail (only used if none is entered above)'],
            ['type' => 'Label', 'name' => 'SecretsStatusLine', 'visible' => $isCloud, 'caption' => $isCloud ? $this->SecretsStatusLine() : ''],
            ['type' => 'Select', 'name' => 'InexogyMeterID', 'visible' => $isCloud, 'caption' => 'Meter UID', 'options' => $meterOpts],
            ['type' => 'Label', 'name' => 'InexogyHintPoll', 'visible' => $isCloud, 'caption' => 'ℹ️ Cloud meters: a sensible polling interval is 60 s or more (at the bottom of the "Polling" panel). The checkbox above is recommended for billing-grade meters, so that an EMS can tell it apart from the real-time meter.'],
            ['type' => 'Label', 'name' => 'InexogyHintMigration', 'visible' => $isCloud, 'caption' => '→ Switching from another Discovergy/Inexogy module while keeping the measurement history? Create this instance with "Communication active = OFF" first and log in, then adopt it with Device swap (formerly MigrationsHub), then set "Communication active = ON". That way the target variable stays without its own history until the takeover.'],
            ['type' => 'Label', 'name' => 'InexogyHintBackfill', 'visible' => $isCloud, 'caption' => '📊 Backfill load profile (15-minute values): fills the archive of "Active energy import/export" (and "Total active power") retroactively with the real meter readings from the Inexogy load profile — more precise than the running polling interval, e.g. to check a bill. Also suitable for larger backlogs (several months) — it then runs internally in blocks and may take a while. Clicking again (e.g. weekly) only backfills the values that have arrived in the meantime.'],
            ['type' => 'NumberSpinner', 'name' => 'InexogyBackfillDays', 'visible' => $isCloud, 'caption' => 'Backfill the load profile of the last … days', 'minimum' => 1, 'maximum' => 730],
            ['type' => 'Button', 'name' => 'InexogyBackfillButton', 'visible' => $isCloud, 'caption' => '📊  Backfill load profile into the archive now', 'onClick' => 'MHUB_BackfillInexogyArchive($id);'],
            ['type' => 'Label', 'name' => 'InexogyBackfillResult', 'caption' => '', 'visible' => false],
            ['type' => 'Label', 'name' => 'InexogyArchiveStatus', 'caption' => $archiveStatusCaption, 'visible' => $archiveStatusVisible],
            ['type' => 'Label', 'name' => 'InexogyHintAutoBackfill', 'visible' => $isCloud, 'caption' => '🆕 The backfill above can also be run automatically and repeatedly — handy so that the check does not depend on manual clicking. 15 minutes is enough: Inexogy delivers the load profile in 15-minute values only anyway, asking more often brings no fresher data. Each run first looks in the archive for what is already there and fetches only what is missing — usually just the last 15 minutes, no more.'],
            ['type' => 'CheckBox', 'name' => 'InexogyAutoBackfillEnabled', 'visible' => $isCloud, 'caption' => 'Backfill load profile automatically and repeatedly'],
            ['type' => 'NumberSpinner', 'name' => 'InexogyAutoBackfillIntervalMin', 'visible' => $isCloud, 'caption' => 'every … minutes', 'minimum' => 15, 'maximum' => 1440],
            ['type' => 'NumberSpinner', 'name' => 'InexogyAutoBackfillDays', 'visible' => $isCloud, 'caption' => 'Upper limit for a larger gap (e.g. after an outage): at most … days back', 'minimum' => 1, 'maximum' => 30],
            ['type' => 'Label', 'name' => 'InexogyHintCleanup', 'visible' => $isCloud, 'caption' => '🧹 Live intermediate values: the live meter reading from Inexogy lags 15–20 min behind, but ended up in the archive with its arrival time between the official quarter-hour values. Every small step backwards created this way is counted twice by Symcon\'s counter compaction (daily values too high). Since 0.26.9 this no longer happens; here the past can be cleaned up — only outdated live values, official values are left untouched.'],
            ['type' => 'NumberSpinner', 'name' => 'InexogyCleanupDays', 'visible' => $isCloud, 'caption' => 'Period of the cleanup (at most 45 days)', 'minimum' => 1, 'maximum' => 45, 'suffix' => MHUB_Text::t(' days')],
            ['type' => 'Button', 'name' => 'InexogyCleanupCheck', 'visible' => $isCloud, 'caption' => '🔎  Check live intermediate values (dry run, changes nothing)', 'onClick' => 'echo MHUB_CheckInexogyArchiveCleanup($id, $InexogyCleanupDays);'],
            ['type' => 'Button', 'name' => 'InexogyCleanupRun', 'visible' => $isCloud, 'caption' => '🧹  Clean up live intermediate values', 'confirm' => MHUB_Text::t('Delete outdated live intermediate values from the archive? Look at the dry run first — deleting cannot be undone.'), 'onClick' => 'echo MHUB_CleanInexogyArchive($id, $InexogyCleanupDays);'],
            [
                'type'     => 'Select',
                'name'     => 'ConnectionMode',
                'visible'  => !$isCloud,
                'caption'  => 'Connection path',
                'options'  => [
                    ['caption' => 'Direct (own connection)', 'value' => 'direct'],
                    ['caption' => 'Symbox gateway (built-in RS485 port)', 'value' => 'gateway'],
                ],
                'onChange' => 'MHUB_OnChangeConnectionMode($id, $ConnectionMode);',
            ],
            ['type' => 'Label', 'name' => 'ConnectionModeGatewayWarning', 'visible' => !$isCloud && $connectionMode === 'gateway', 'caption' => '⚠️ This connection path is new and has not been tried on many devices yet (SUITE.md 9j) — read access verified against the raw code of Symcon\'s own reference module. Do NOT use write-capable meter types (blue\'Log RPC/Power Control) productively over this path — their setpoint write access is only an untested derivation here. Without a selected and connected bridge this mode delivers no values.'],
            ['type' => 'Label', 'name' => 'ConnectionModeGatewayUnitIdHint', 'visible' => !$isCloud && $connectionMode === 'gateway', 'caption' => 'ℹ️ Host/port/unit ID are not needed in this mode. Procedure: create a native "ModBus gateway" for the device and set its "DeviceID" (= unit ID) to the device\'s Modbus address — select this gateway below and click "Create bridge and connect", then "Apply changes". It also works by hand: create an "NRG-Stack MeterHub bridge", connect it to the gateway via "Change gateway" and select it below. A bridge serves exactly one unit ID.'],
            ['type' => 'SelectInstance', 'name' => 'GatewayPick', 'visible' => !$isCloud && $connectionMode === 'gateway', 'caption' => 'ModBus gateway to the device', 'moduleID' => MHUB_ModbusGatewayClient::GATEWAY_GUID],
            ['type' => 'Button', 'name' => 'BtnCreateBridge', 'visible' => !$isCloud && $connectionMode === 'gateway', 'caption' => 'Create bridge and connect', 'onClick' => 'echo MHUB_CreateBridge($id, $GatewayPick);'],
            ['type' => 'SelectInstance', 'name' => 'BridgeInstanceID', 'visible' => !$isCloud && $connectionMode === 'gateway', 'caption' => 'NRG-Stack bridge to the ModBus gateway', 'moduleID' => self::BRIDGE_GUID, 'onChange' => 'MHUB_OnChangeBridge($id, $BridgeInstanceID);'],
            ['type' => 'Label', 'name' => 'BridgeStatusLine', 'visible' => !$isCloud && $connectionMode === 'gateway', 'caption' => $this->BridgeStatusLine($this->ReadPropertyInteger('BridgeInstanceID'))],
            ['type' => 'Label', 'name' => 'UnitIdAutoLine', 'visible' => !$isCloud && $connectionMode === 'gateway', 'caption' => $this->UnitIdAutoLine($this->ReadPropertyInteger('BridgeInstanceID')), 'color' => self::AutoLineColor($this->UnitIdAutoLine($this->ReadPropertyInteger('BridgeInstanceID')))],
            ['type' => 'ValidationTextBox', 'name' => 'Host', 'visible' => !$isCloud && $connectionMode !== 'gateway', 'caption' => 'IP address', 'validate' => ($isCloud || $connectionMode === 'gateway') ? '' : '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
            ['type' => 'NumberSpinner', 'name' => 'Port', 'visible' => !$isCloud && $connectionMode !== 'gateway', 'caption' => 'TCP port', 'minimum' => 1, 'maximum' => 65535],
            ['type' => 'NumberSpinner', 'name' => 'UnitId', 'visible' => !$isCloud && $connectionMode !== 'gateway', 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
        ];

        $groupItems = [];
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            $groupItems[] = [
                'type'    => 'CheckBox',
                'name'    => $propName,
                'caption' => $group['caption'],
            ];
        }

        // --- Funktionszuordnung: Messmodus ist die Weiche ---------------------
        $funcOptions = [];
        foreach (self::FUNCTIONS as $key => $def) {
            $funcOptions[] = ['caption' => $def[0], 'value' => $key];
        }
        $funcItems = [
            ['type' => 'Label', 'name' => 'MeasureModeSingle', 'visible' => $this->IsSinglePhaseMeter(), 'caption' => 'ℹ️ This meter measures only one phase — the function applies to the whole device.'],
            ['type' => 'Label', 'name' => 'MeasureModeIntro', 'visible' => !$this->IsSinglePhaseMeter(), 'caption' => 'First decide HOW this meter measures — this determines whether one function is assigned to the whole device or one per phase.'],
            [
                'type'    => 'Select',
                'name'    => 'MeasureMode',
                'visible' => !$this->IsSinglePhaseMeter(),
                'caption' => 'Measurement mode',
                'options' => [
                    ['caption' => 'Three-phase — one consumer across all 3 phases (e.g. grid connection, heat pump)', 'value' => 'combined'],
                    ['caption' => 'Single-phase separately — 3 independent consumers (one per phase)',                    'value' => 'perphase'],
                ],
            ],
            ['type' => 'Label', 'name' => 'MeasureModeHint', 'visible' => !$this->IsSinglePhaseMeter(), 'caption' => 'Click "Apply" once after switching — the matching assignment fields then appear here.'],
        ];
        if ($this->IsPerPhaseMode()) {
            $funcItems[] = ['type' => 'Label', 'caption' => '⚡ Assign one consumer per phase. For separate energy meters, also enable the group "Energy per phase" in the "Data points" panel (if the meter supports it).'];
            foreach (['L1', 'L2', 'L3'] as $ph) {
                $funcItems[] = ['type' => 'Select', 'name' => 'Func' . $ph, 'caption' => MHUB_Text::t('Phase %d — function', $ph), 'options' => $funcOptions];
                $funcItems[] = ['type' => 'ValidationTextBox', 'name' => 'FuncLabel' . $ph, 'caption' => MHUB_Text::t('Phase %d — custom label (optional, e.g. "Garage rear")', $ph)];
            }
        } else {
            $funcItems[] = ['type' => 'Select', 'name' => 'FuncTotal', 'caption' => 'Function of this meter', 'options' => $funcOptions];
            $funcItems[] = ['type' => 'ValidationTextBox', 'name' => 'FuncTotalLabel', 'caption' => 'Custom label (optional, replaces the name of the function)'];
        }
        $funcItems[] = ['type' => 'CheckBox', 'name' => 'FuncMirrors', 'caption' => 'Create additional collective variables per function (power/import/feed-in under "Functions")'];
        $funcItems[] = [
            'type' => 'PopupButton', 'caption' => 'What exactly does this create, and what for?', 'width' => '460px',
            'popup' => [
                'caption' => 'Collective variables per function',
                'items' => [
                    ['type' => 'Label', 'caption' => 'Without this switch the assigned values are only found under their technical name (e.g. "Total active power") in this instance — the function is assigned, but nowhere separately visible.'],
                    ['type' => 'Label', 'caption' => 'When switched on, the module additionally creates clearly named copies directly under the instance (e.g. "Heat pump — Active power", "Heat pump — Active energy import") — handy for a dashboard or a quick overview, without having to look up the function assignment first.'],
                    ['type' => 'Label', 'caption' => 'Other modules (EMS, tiles) do NOT need these collective variables — they read the assignment directly via MHUB_GetFunctions(). The switch is purely a convenience for your own object tree.'],
                ],
            ],
        ];
        $funcItems[] = ['type' => 'Label', 'caption' => MHUB_Text::t('The assignment renames the affected variables (e.g. "Heat pump — Active energy import") and sets a matching icon. Other modules (EMS, tiles) can read the assignment via MHUB_GetFunctions(%d).', $this->InstanceID)];

        // --- Virtueller Zähler: Hinweis und Generator ------------------------
        $meterOptions = [['caption' => '— please select —', 'value' => 0]];
        foreach (IPS_GetInstanceListByModuleID(self::GUID_METER) as $iid) {
            if ($iid !== $this->InstanceID) {
                $meterOptions[] = ['caption' => IPS_GetName($iid) . '  (#' . $iid . ')', 'value' => $iid];
            }
        }
        $virtualItems = [
            ['type' => 'Label', 'caption' => 'A virtual meter adds up several real meters — typically "main meter minus sub-meters = remainder". Each meter is a row with a share in percent (100 % = add, −100 % = subtract); the result is the sum of all shares.'],
        ];
        $member = $this->VirtualMemberships();
        if ($member) {
            foreach ($member as $iid => $keys) {
                $virtualItems[] = ['type' => 'Label', 'caption' => MHUB_Text::t('🔗 This meter is included in "%s" (#%d) as "%s".', IPS_GetName($iid), $iid, implode(MHUB_Text::t('", "'), $keys))];
            }
            $virtualItems[] = ['type' => 'Label', 'caption' => 'Further meters are best added there — a second virtual meter with the same device easily leads to double bookkeeping.'];
        } else {
            $virtualItems[] = ['type' => 'Label', 'caption' => 'This meter is not yet part of any virtual meter. You can create one right here: select the other meters involved, set the role, press the button. A separate "MeterHubVirtual" instance is created — nothing changes in this configuration.'];
            $virtualItems[] = [
                'type' => 'Select', 'name' => 'VirtualRole', 'caption' => 'Role of this meter',
                'options' => [
                    ['caption' => 'Superordinate — the selected meters are subtracted from this one (gives the remainder)', 'value' => 'parent'],
                    ['caption' => 'Equal rank — all meters are simply added (gives the total)',              'value' => 'sibling'],
                ],
            ];
            $virtualItems[] = [
                'type' => 'List', 'name' => 'VirtualPartners', 'caption' => 'Other meters involved',
                'rowCount' => 4, 'add' => true, 'delete' => true,
                'columns' => [
                    ['caption' => 'MeterHub instance', 'name' => 'InstanceID', 'width' => 'auto', 'add' => 0,
                     'edit' => ['type' => 'Select', 'options' => $meterOptions]],
                ],
            ];
            $virtualItems[] = ['type' => 'Button', 'caption' => '🧮  Create virtual meter', 'onClick' => 'MHUB_CreateVirtual($id, $VirtualPartners, $VirtualRole);'];
            $virtualItems[] = ['type' => 'Label', 'name' => 'VirtualResult', 'caption' => '', 'visible' => false];
            $virtualItems[] = ['type' => 'Label', 'caption' => 'The new instance can then be extended freely — also with meters that do not come from MeterHub at all (sockets, light and blind switches). It has its own search for that.'];
        }

        // Feste Elemente oben (Zweck, Neu, Dokumentation, Grundfelder), dazwischen die wählbaren Fachpanels, unten Aufbau/Forum/Über.
        $top = [
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖  Documentation & help',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => MHUB_Text::t('MeterHub %s — version of this guide.', self::NEWS_VERSION)],
                        ['type' => 'Label', 'caption' => 'MeterHub reads energy meters from various manufacturers directly via Modbus TCP. Choose the meter type, enter the IP address (and port/unit ID if needed), and enable data point groups as required.'],
                        ['type' => 'Label', 'caption' => '🔀 Planning to switch from another meter/hub module while keeping the measurement history? Create and configure this instance with "Communication active = OFF" first, then take over the old history with Device swap (formerly MigrationsHub), then set "Communication active = ON". That way the target variable stays without values of its own that would overlap with the old history until the takeover.'],
                        ['type' => 'Label', 'caption' => 'Supported meters: Siemens SENTRON PAC2200 (FC 0x03); Janitza UMG series (UMG 604/605/509/512/806/96PA/801 classic map, UMG 800 factory map, FC 0x03); Eastron SDM72D-M v2, WhatWatt and Phoenix Contact EEM-EM375/EEM-XM (FC 0x04, input registers).'],
                        ['type' => 'Label', 'caption' => 'Note on Eastron/Phoenix: these mostly speak Modbus RTU and connect through an RTU/TCP gateway (enter its IP). The Eastron factory default device address is 1; Phoenix EEM-EM375 often uses unit ID 255, EEM-XM mostly 1. WhatWatt speaks Modbus TCP directly.'],
                        ['type' => 'Label', 'caption' => '🔌 Eastron SDM120/SDM220/SDM230 (single-phase): confirmed on real hardware by a tester. Pure RS485 devices (Modbus address 1 by factory default) — via the connection path "Symbox gateway" or an RTU/TCP gateway. No total registers as on the SDM630: the active power is at register 12.'],
                        ['type' => 'Label', 'caption' => '🧪 Experimental: Socomec Countis and MBS Professional 3-75 are derived from templates and not yet tested on real hardware — please compare the measured values with the device display. If values look implausible, the WordSwap or invert switch helps.'],
                        ['type' => 'Label', 'caption' => '🔌 Shelly Pro 3EM: Modbus TCP must be enabled on the device first (Settings → Modbus, port 502). Read via FC 0x04, float word-swapped (CDAB); wire addresses = documentation − 30000 (measurements from 1011, energy 1162/1164). Verified on a real device.'],
                        ['type' => 'Label', 'caption' => '🔌 go-e Controller: Modbus TCP must be enabled on the device first (go-e app: Internet → Advanced settings → Modbus, or HTTP API men=true) — otherwise port 502 stays closed; after enabling, switch the setting off and on once if necessary. Core values come from the Grid category; sensors 1-6 and the categories Home/Car/Relays/Solar/Battery can be enabled. Verified on a real device. (The go-e wallboxes themselves are handled by the ChargerHub module.)'],
                        ['type' => 'Label', 'caption' => '⚠️ go-e Controller + surplus charging: the controller can regulate the go-e wallboxes BY ITSELF (PV surplus charging, load limiting — an on-device control loop). If an EMS is to control the wallboxes instead, this internal control must be disabled on the wallboxes — otherwise two controllers work against each other. This module only reads and is not affected; the control state is not visible via Modbus, only on the wallboxes themselves (go-e API: usePvSurplus, load management, modelStatus).'],
                        ['type' => 'Label', 'caption' => '🆕 Meteocontrol blue\'Log SCADA: a blue\'Log can offer EVERY connected device (inverter, meter …) under its own "SCADA address" — this address is the unit ID below, NOT 1. The assignment is on the blue\'Log itself (device list → column "SCADA address"); address 97 is the blue\'Log itself (total values). FC 0x03, float word-swapped (CDAB) — verified on a real solar park.'],
                        ['type' => 'Label', 'caption' => 'ℹ️ Sign convention: + = import from the grid, − = feed-in. If the direction does not match your own installation, the invert switch below helps.'],
                        ['type' => 'Label', 'caption' => '🔧 Connection: the meters use Modbus TCP port 502. The unit/device address is usually 1 by factory default (the PAC2200 often answers independently of the unit ID, too).'],
                        ['type' => 'Label', 'caption' => '🔌 Symbox / ModBus gateway: under "Connection" choose the path "Symbox gateway" — only then do the related fields appear. Create a native "ModBus gateway" for the device (its "DeviceID" = the device\'s Modbus address), select it here and click "Create bridge and connect", then "Apply changes". A bridge serves exactly one unit ID; several devices each need a gateway and a bridge. New path, not yet tried on many devices.'],
                        ['type' => 'Label', 'caption' => '⚠️ UMG 800: its Modbus mapping is freely configurable — this driver follows the delivered factory default. If it was changed in the device (GridVis), the addresses may not match.'],
                        ['type' => 'Label', 'caption' => 'Register addresses are in the description field of each variable (object manager, column "Description").'],
                    ],
                ],
                // Zählerbezeichnung + Standort (Dietmars Auftrag 01.09.2026,
                // aus MeterHubVirtual übernommen — identisches Muster,
                // geteilter Standort-Vorschlagspool über beide Modultypen).
                ['type' => 'ValidationTextBox', 'name' => 'InstanceName', 'caption' => '🆕 Meter name', 'value' => IPS_GetName($this->InstanceID), 'onChange' => 'MHUB_RenameInstance($id, $InstanceName);'],
                ['type' => 'Select', 'name' => 'LocationPreset', 'caption' => 'Location (apply a suggestion …)', 'options' => $locationOptions, 'value' => '', 'onChange' => 'MHUB_ApplyLocationPreset($id, $LocationPreset);'],
                ['type' => 'ValidationTextBox', 'name' => 'Location', 'caption' => '🆕 Location (room/floor, free text)'],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'Active',
                    'caption' => 'Communication active',
                ],
                // Immer sichtbar (nicht im eingeklappten Doku-Panel versteckt):
                // Wer eine Instanz von Hand anlegt statt über MeterHubDiscovery,
                // sieht sonst ein Formular mit „Siemens SENTRON PAC2200" bereits
                // vorausgewählt — ohne diesen Hinweis liest sich das leicht als
                // Empfehlung/Standardfall statt als bloßer technischer
                // Platzhalter (19 gleichwertige Optionen, PAC2200 ist keine
                // davon ausgezeichnet). Verbund-Erkenntnis 27.07.2026 (EMS,
                // GoodWe-Netzmesspunkt-Formular): implizit ein Hersteller als
                // „der" Weg dargestellt, obwohl nur einer von mehreren.
                ['type' => 'Label', 'caption' => '👉 First choose the actual meter type — the preselection below is only a technical placeholder, not a recommendation. No Modbus meter of your own? "Inexogy / Discovergy" uses the cloud API instead. The connection fields below adapt automatically to the selection.'],
                [
                    'type'     => 'Select',
                    'name'     => 'Meter',
                    'caption'  => 'Meter type',
                    'onChange' => 'MHUB_OnChangeMeter($id, $Meter);',
                    'options' => [
                        ['caption' => 'Siemens SENTRON PAC2200', 'value' => 'siemens_pac2200'],
                        ['caption' => 'Janitza UMG 604(-PRO)',   'value' => 'janitza_umg604'],
                        ['caption' => 'Janitza UMG 605-PRO',     'value' => 'janitza_umg605'],
                        ['caption' => 'Janitza UMG 509-PRO',     'value' => 'janitza_umg509'],
                        ['caption' => 'Janitza UMG 512-PRO',     'value' => 'janitza_umg512'],
                        ['caption' => 'Janitza UMG 806',         'value' => 'janitza_umg806'],
                        ['caption' => 'Janitza UMG 96PA',        'value' => 'janitza_umg96pa'],
                        ['caption' => 'Janitza UMG 801',         'value' => 'janitza_umg801'],
                        ['caption' => 'Janitza UMG 800 (configurable register map — factory default)', 'value' => 'janitza_umg800'],
                        ['caption' => 'Eastron SDM72D-M v2',     'value' => 'eastron_sdm72d'],
                        ['caption' => 'Eastron SDM630 v2',       'value' => 'eastron_sdm630'],
                        ['caption' => 'Eastron SDM120 (single-phase)', 'value' => 'eastron_sdm120'],
                        ['caption' => 'Eastron SDM220 (single-phase)', 'value' => 'eastron_sdm220'],
                        ['caption' => 'Eastron SDM230 (single-phase)', 'value' => 'eastron_sdm230'],
                        ['caption' => 'WhatWatt',                'value' => 'whatwatt'],
                        ['caption' => 'Phoenix Contact EEM-EM375', 'value' => 'phoenix_eem375'],
                        ['caption' => 'Phoenix Contact EEM-XM',  'value' => 'phoenix_eemxm'],
                        ['caption' => 'Carlo Gavazzi EM24 / EM300 / ET340', 'value' => 'carlo_gavazzi_em'],
                        ['caption' => 'Socomec Countis (experimental)', 'value' => 'socomec_countis'],
                        ['caption' => 'MBS Professional 3-75 (experimental)', 'value' => 'mbs_professional'],
                        ['caption' => 'Shelly Pro 3EM', 'value' => 'shelly_pro3em'],
                        ['caption' => 'go-e Controller', 'value' => 'goe_controller'],
                        ['caption' => 'Inexogy / Discovergy (cloud API, no Modbus)', 'value' => 'inexogy'],
                        ['caption' => 'Meteocontrol blue\'Log SCADA – inverter (SCADA address = unit ID)', 'value' => 'bluelog_scada_inverter'],
                        ['caption' => 'Meteocontrol blue\'Log SCADA – meter (SCADA address = unit ID)', 'value' => 'bluelog_scada_meter'],
                        ['caption' => 'Meteocontrol blue\'Log SCADA – data logger, total of all inverters (unit ID 97)', 'value' => 'bluelog_scada_logger'],
                    ],
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'Role',
                    'caption' => 'Role of the meter',
                    'options' => [
                        ['caption' => 'Grid/connection-point meter (+ import / − feed-in)', 'value' => 'grid'],
                        ['caption' => 'Sub-meter / consumer (measures in one direction)', 'value' => 'consumption'],
                        ['caption' => 'Sub-meter / generator (measures in one direction)', 'value' => 'production'],
                    ],
                ],
                ['type' => 'Label', 'caption' => $this->RolePlausibilityFormText()],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'BillingGrade',
                    'caption' => 'Billing-grade meter at the grid connection point (calibration-law-compliant smart metering system, iMSys/mMSD) — the value that appears on the electricity bill',
                ],
            ];

        // Fachpanels in Standardreihenfolge — Reihenfolge und Aufklapp-Zustand wählt der Nutzer im Panel „Formular: Aufbau“ (SUITE.md 3a).
        $free = [
                'write' => [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '⚡  Setpoint write access (blue\'Log RPC/Power Control)',
                    'expanded' => true,
                    'items'    => [
                        [
                            'type' => 'Label', 'name' => 'WriteWarningPanel',
                            'caption' => '⚠️ This instance WRITES a setpoint to a real control interface. It collides with any other system already serving the same channel — before enabling, make sure no second writer (script, EMS, third-party system) accesses the same slave ID of the same blue\'Log.',
                            'visible' => false,
                        ],
                        [
                            'type' => 'Label', 'name' => 'WriteWarningGridop',
                            'caption' => '⚠️ Power Control role: this instance presents itself to the blue\'Log as GRID OPERATOR. Enable only if this role actually applies. No watchdog/validity-time mechanism — a value that has been set stays until it is actively overwritten.',
                            'visible' => false,
                        ],
                        [
                            'type' => 'Select', 'name' => 'SetpointMode', 'caption' => 'Setpoint mode',
                            'options' => [
                                ['caption' => 'Relative (% of connected load)', 'value' => 'rel'],
                                ['caption' => 'Absolute (watts)',                    'value' => 'abs'],
                            ],
                            'onChange' => 'MHUB_OnChangeSetpointMode($id, $SetpointMode);',
                            'visible' => false,
                        ],
                        ['type' => 'NumberSpinner', 'name' => 'TargetSetpoint', 'caption' => MHUB_Text::t('Target value (%, relative)'), 'digits' => 3, 'visible' => false],
                        [
                            'type' => 'Select', 'name' => 'FailsafeMode', 'caption' => 'Failure behavior (on deactivation)',
                            'options' => [
                                ['caption' => 'Write default setpoint', 'value' => 'default'],
                                ['caption' => 'Hold last setpoint',    'value' => 'hold'],
                            ],
                            'visible' => false,
                        ],
                        ['type' => 'NumberSpinner', 'name' => 'FailsafeDefaultPercent', 'caption' => 'Default setpoint (%, always relative)', 'digits' => 1, 'minimum' => 0, 'maximum' => 125, 'visible' => false],
                        ['type' => 'NumberSpinner', 'name' => 'RpcValidTimeMin', 'caption' => 'Validity time (minutes, RPC only)', 'minimum' => 1, 'maximum' => 255, 'visible' => false],
                        ['type' => 'Button', 'name' => 'BtnWriteNow', 'caption' => '▶  Write target value now', 'onClick' => 'echo MHUB_SetBlueLogTarget($id, $TargetSetpoint);', 'visible' => false],
                    ],
                ],
                'func' => [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🏷️  Function assignment',
                    'expanded' => false,
                    'items'    => $funcItems,
                ],
                'virtual' => [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🧮  Virtual meter (total / remainder from several meters)',
                    'expanded' => false,
                    'items'    => $virtualItems,
                ],
                'conn' => [
                    'type'    => 'ExpansionPanel',
                    'caption' => $isCloud ? '🔐  Cloud access' : MHUB_Text::t('🔌  Connection'),
                    'expanded' => true,
                    'items' => $connectionItems,
                ],
                'polling' => [
                    'type'    => 'ExpansionPanel',
                    'caption' => '⏱️  Polling',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'IntervalFast', 'caption' => 'Fast interval (instantaneous values, seconds)', 'minimum' => 2, 'maximum' => 60, 'suffix' => 's'],
                        ['type' => 'NumberSpinner', 'name' => 'IntervalSlow', 'caption' => 'Slow interval (energy meters, seconds)', 'minimum' => 10, 'maximum' => 3600, 'suffix' => 's'],
                    ],
                ],
                'archPower' => [
                    // Zwei eigene Panels statt einem gemeinsamen (Dietmars
                    // Rückmeldung 31.08.2026: Symcons Formularsprache kennt
                    // kein horizontales Nebeneinander — schon bei den
                    // PopupButtons geprüft. Zwei einzeln auf-/zuklappbare
                    // Panels halten wenigstens die sichtbare Feldzahl klein.
                    'type'    => 'ExpansionPanel',
                    'caption' => '🗄️  Archive compaction: power/instantaneous values',
                    'expanded' => false,
                    'items' => array_merge(
                        [['type' => 'Label', 'name' => 'ArchiveStatusLine', 'caption' => $this->ArchiveStatusLine()]],
                        [['type' => 'Label', 'caption' => 'Automatically reduces the level of detail of older archive values (saves memory), set anew on every "Apply" — only for variables that are archived (you switch archiving on yourself, see the notice at the top of the form). Each stage only takes effect if its resolution is actually coarser than the respective polling interval above — otherwise there would be nothing to compact.']],
                        $this->CompactionFields('Power')
                    ),
                ],
                'archEnergy' => [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🗄️  Archive compaction: energy meters (active energy)',
                    'expanded' => false,
                    'items' => array_merge(
                        [['type' => 'Label', 'caption' => 'Can be set independently of power — energy meter readings often have different retention requirements.']],
                        $this->CompactionFields('Energy')
                    ),
                ],
                'repairEnergy' => [
                    // Energie-Archiv prüfen/reparieren (0.26.6, Anlass: Inexogy
                    // lieferte am 10./11.09.2026 Zählerstand 0 für eine
                    // Übertragungslücke). Zuerst immer Probelauf, geändert wird
                    // nur über den zweiten Knopf mit Rückfrage.
                    'type'    => 'ExpansionPanel',
                    'caption' => '🩺  Check / repair energy archive',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Searches the archived meter readings (import/feed-in) for zero values and steps backwards — e.g. when a cloud meter fills a transmission gap with 0. Such points produce huge phantom consumption in daily/monthly values.'],
                        ['type' => 'Label', 'caption' => 'Repair: delete invalid points and distribute the known quantity between the last valid reading before and the first one after — following the curve of a reference meter on the same connection (which of its meters fits, the module detects by itself from the 24 h before), otherwise evenly. The power in such gaps is recalculated from the filled-in energy. Gaps without a valid reading afterwards (possibly a meter replacement) are left untouched.'],
                        ['type' => 'SelectInstance', 'name' => 'RepairReference', 'caption' => 'Reference meter for the shape of the gap (empty = evenly)'],
                        ['type' => 'NumberSpinner', 'name' => 'RepairDays', 'caption' => 'Period (at most 45 days)', 'minimum' => 1, 'maximum' => 45, 'suffix' => MHUB_Text::t(' days')],
                        ['type' => 'CheckBox', 'name' => 'RepairBackward', 'caption' => 'Also repair individual steps backwards (better switched off for cloud meters with mixed live and quarter-hour values — the lower point there can be the official value)'],
                        ['type' => 'Button', 'caption' => '🔎  Check (dry run, changes nothing)', 'onClick' => 'echo MHUB_CheckEnergyArchive($id, $RepairReference, $RepairDays, $RepairBackward);'],
                        ['type' => 'Button', 'caption' => '🛠️  Repair', 'confirm' => MHUB_Text::t('Delete invalid archive values and fill the gaps? Look at the dry run first — deleting cannot be undone.'), 'onClick' => 'echo MHUB_RepairEnergyArchive($id, $RepairReference, $RepairDays, $RepairBackward);'],
                    ],
                ],
                'repairDirection' => [
                    // Richtung im Archiv (0.27.0): Altfälle früherer
                    // PowerInvert-Umschaltungen. Zuerst immer Probelauf.
                    'type'    => 'ExpansionPanel',
                    'caption' => '↔️  Check / correct direction in the archive',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'For earlier switches of "Import/feed-in swapped" (before version 0.27.0): back then import and feed-in jumped onto each other, and the stored power kept its old sign from before the switch. Since 0.27.0 none of this happens when switching.'],
                        ['type' => 'Label', 'caption' => 'Energy: each meter variable becomes a continuous series again. The module recognizes swapped sections by a reading jumping to that of the other meter. A change of unit or a meter replacement is not such a jump and stays untouched.'],
                        ['type' => 'Label', 'caption' => 'Power: for each quarter hour the direction from the meter readings (import − feed-in) is compared with the sign of the stored power. Opposing sections are flipped, the edges determined to the minute. This only works once the energy is right — so check twice if necessary.'],
                        ['type' => 'Label', 'caption' => 'Whether the direction is right today is shown by the direction check under "Data points" at the switch. This correction only makes the archive consistent in itself. Before every change a backup is saved as a JSON file in the Symcon directory.'],
                        ['type' => 'NumberSpinner', 'name' => 'DirectionRepairDays', 'caption' => 'Period (at most 120 days)', 'minimum' => 1, 'maximum' => 120, 'suffix' => MHUB_Text::t(' days')],
                        ['type' => 'Button', 'caption' => '🔎  Check (dry run, changes nothing)', 'onClick' => 'echo MHUB_CheckDirectionArchive($id, $DirectionRepairDays);'],
                        ['type' => 'Button', 'caption' => '🛠️  Correct', 'confirm' => MHUB_Text::t('Correct the sections shown in the dry run in the archive now? A backup is made first.'), 'onClick' => 'echo MHUB_RepairDirectionArchive($id, $DirectionRepairDays);'],
                    ],
                ],
                'datapoints' => [
                    'type'    => 'ExpansionPanel',
                    'caption' => '📊  Data points',
                    'expanded' => true,
                    'items' => array_merge($groupItems, [
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'PowerInvert',
                            'caption' => 'Import/feed-in swapped — inverts active power and swaps the energy meters',
                        ],
                        ['type' => 'Label', 'caption' => $this->DirectionFormText()],
                        [
                            'type' => 'PopupButton', 'caption' => 'When do I need this, and what exactly does it do?', 'width' => '480px',
                            'popup' => [
                                'caption' => 'Import/feed-in swapped',
                                'items' => [
                                    ['type' => 'Label', 'caption' => 'Typical reason: the meter is connected the wrong way round (current transformer reversed) — what is actually import it reports as feed-in and vice versa.'],
                                    ['type' => 'Label', 'caption' => 'For active power a sign change (+/−) is enough; this switch does that automatically.'],
                                    ['type' => 'Label', 'caption' => 'For the energy meters that is NOT enough: "import" and "export" are two separate, always positive meter readings. A sign change would turn them into negative readings. The right approach instead is to SWAP the two target variables — a value that actually belongs to the import meter then correctly lands in the export variable and vice versa. This switch does exactly that in addition to the sign change.'],
                                    ['type' => 'Label', 'caption' => 'If this meter has only one direction (e.g. pure consumers without feed-in), the existing value simply stays on its variable — no data loss.'],
                                    ['type' => 'Label', 'caption' => 'Switching without a jump (since 0.27.0): when switching, the two energy meter variables swap their role (name and ident). Each keeps its meter reading and its history — no jumps, no phantom consumption in daily and monthly values. Switching back undoes it. Dashboard and virtual meters adopt this by themselves; please check your own scripts with hard-coded variable IDs.'],
                                    ['type' => 'Label', 'caption' => 'Whether the switch is set correctly is shown by the direction check below: it compares the power with a second grid meter or the inverter\'s grid measurement, alternatively with the PV generation. Earlier switches (before 0.27.0) are corrected by the panel "Check / correct direction in the archive".'],
                                ],
                            ],
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'EnergyUnitWh',
                            'caption' => 'Output energy in Wh instead of kWh (base unit; the new Symcon presentation then scales itself to Wh/kWh/MWh)',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'WordSwap',
                            'caption' => 'Swap float word order (CDAB) — if measured values are implausibly large/small (e.g. some Phoenix EEM-XM)',
                        ],
                    ]),
                ],
        ];
        if ($freeOnly) {
            return $free;
        }
        $lay = $this->ApplyFormLayout($free);

        $form = [
            'elements' => array_values(array_filter(array_merge([$this->PurposeIntro(), $this->NewsBanner(), $this->ArchivingPanel()], $top, $lay['panels'],
                [$this->LayoutPanel($lay['layout'], $free), $this->ForumHint(), $this->LicenseHint()]))),
            'actions' => [
                ['type' => 'Button', 'caption' => 'Test connection / read data now', 'onClick' => 'echo MHUB_TestConnection($id);'],
                ['type' => 'Button', 'caption' => '🔄  Force apply (without a form change)', 'onClick' => MHUB_Text::t('IPS_ApplyChanges($id); echo \'✅ ApplyChanges() executed.\';'), 'confirm' => MHUB_Text::t('Apply the instance now (ApplyChanges)?')],
            ],
            // 104 bewusst neutral und in jedem Verbindungsweg gleich: die Statuszeile folgt dem GESPEICHERTEN
            // Stand, ein Wechsel des Verbindungswegs im offenen Formular ändert sie nicht (Forum-Feedback 20.09.2026).
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Please set up the connection.'],
                ['code' => 102, 'icon' => 'active',   'caption' => 'Connection active.'],
                ['code' => 201, 'icon' => 'error',    'caption' => ($connectionMode === 'gateway' && !$isCloud)
                    ? MHUB_Text::t('Connection error – no response via the bridge: check the bridge and the ModBus gateway (unit ID = DeviceID at the gateway).')
                    : MHUB_Text::t('Connection error – meter not reachable.')],
            ],
        ];

        return $form;
    }

    // -----------------------------------------------------------------------
    // Formular: Aufbau (SUITE.md 3a) — Aufklapp-Zustand, Reihenfolge und
    // Ausblenden der Fachpanels wählbar; Referenz Batteriewächter.
    // -----------------------------------------------------------------------

    /**
     * Reihenfolge und Aufklapp-Zustand der Fachpanels aus der Einstellung „FormLayout“.
     * Unbekannte oder fehlende Einträge fallen auf die Standardreihenfolge und „Standard“ zurück.
     *
     * @param string[] $keys Schlüssel der Fachpanels in Standardreihenfolge
     * @return array key => ['open' => 'std'|'open'|'closed'|'hidden', 'pos' => int, 'idx' => int], in der anzuwendenden Reihenfolge
     */
    private function formLayout(array $keys): array
    {
        $saved = json_decode((string)$this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string)$row['Key']] = $row;
            }
        }
        $out = [];
        foreach ($keys as $i => $k) {
            $r = $by[$k] ?? [];
            // Kontrollkästchen; Vorrang bei Widerspruch: ausgeblendet vor zugeklappt vor aufgeklappt, keines = Standard
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = [
                'open' => $open,
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, function ($a, $b) { return [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]; });
        return $out;
    }

    /** Name eines Fachpanels für die Liste im Aufbau-Panel (ohne Klammerzusatz). */
    private function layoutPanelName(array $panel, string $key): string
    {
        // Der Name steht als Wert in einer Liste (nicht als Beschriftung) — die Konsole übersetzt ihn nicht, deshalb hier.
        return trim((string)preg_replace('/\s*\(.*$/u', '', MHUB_Text::t((string)($panel['caption'] ?? $key))));
    }

    /**
     * Wendet den gewählten Aufbau auf die Fachpanels an.
     *
     * @param array $free key => Panel, in Standardreihenfolge
     * @return array ['layout' => formLayout(), 'panels' => Panels in gewählter Reihenfolge]
     */
    private function ApplyFormLayout(array $free): array
    {
        $layout = $this->formLayout(array_keys($free));
        $panels = [];
        foreach ($layout as $key => $l) {
            $panel = $free[$key];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // Nicht weglassen: die Felder bleiben im Formular, gespeicherte Werte bleiben beim „Übernehmen“ erhalten
                $panel['visible'] = false;
            }
            $panels[] = $panel;
        }
        return ['layout' => $layout, 'panels' => $panels];
    }

    /** Wählbarer Aufbau des Formulars: je Fachpanel Aufklapp-Zustand beim Öffnen und Position. */
    private function LayoutPanel(array $layout, array $free): array
    {
        $rows   = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = $this->layoutPanelName($free[$key], $key);
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => MHUB_Text::t('🙈 Hidden: %s — to show again, clear the "Hidden" checkbox. Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => MHUB_Text::t('🪟  Form: layout%s', $hidden ? MHUB_Text::t(' (%s hidden)', count($hidden)) : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Here you decide which sections are expanded when the form opens and in which order they appear. Symcon does not remember expanding and collapsing by itself, so this setting applies every time the form opens. It takes effect after “Apply” the next time the form is opened.'],
                ['type' => 'Label', 'caption' => 'No tick means "default": as the module intends. The position is a number; with the same number the default order stays. "Hidden" takes a section out of the display without it losing anything: its settings are kept and keep working (meant for sections you rarely need). To show it again, remove the tick at "Hidden" or click "Reset to default". If several ticks are set, "Hidden" wins over "Collapsed" over "Expanded". The sections at the top (purpose, news, documentation) and at the bottom (feedback, About this module) stay where they are and cannot be hidden.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Areas', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Area', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => 'Expanded', 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Collapsed', 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo MHUB_ResetFormLayout($id);'],
            ]),
        ];
    }

    /** Setzt Aufklapp-Zustand und Reihenfolge der Fachpanels im offenen Formular auf den Standard (gilt nach „Übernehmen“). */
    public function ResetFormLayout(): string
    {
        $rows = [];
        $i = 0;
        foreach ($this->BuildForm(true) as $key => $panel) {
            $rows[] = ['Key' => $key, 'Panel' => $this->layoutPanelName($panel, $key), 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => ++$i];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return MHUB_Text::t('ℹ️ Reset to default. Click "Apply" below to save.');
    }

    /**
     * Blendet beim Umschalten des Zählertyps sofort die passende
     * Verbindungsart ein/aus — ohne "Übernehmen" abzuwarten. Ohne das würde
     * ein frisch auf Inexogy umgeschaltetes Formular weiterhin das
     * Host-Feld samt IP-Pflichtformat zeigen (GetConfigurationForm() läuft
     * nur beim Öffnen der Maske, nicht bei jeder Auswahländerung), mit
     * nichts Sinnvollem, was man dort eintragen könnte.
     */
    public function OnChangeMeter(string $meter)
    {
        $isCloud = in_array($meter, self::CLOUD_METERS, true);
        foreach (['InexogyIntro', 'InexogyStatusLine', 'SecretsIntro', 'SecretsEnabled', 'SecretsInstanceID', 'SecretsRecord', 'SecretsPassField', 'SecretsUserField', 'SecretsStatusLine', 'InexogyEmail', 'InexogyPassword', 'InexogyLoginButton', 'InexogyMeterID', 'InexogyHintPoll', 'InexogyHintMigration', 'InexogyHintBackfill', 'InexogyBackfillDays', 'InexogyBackfillButton', 'InexogyHintAutoBackfill', 'InexogyAutoBackfillEnabled', 'InexogyAutoBackfillIntervalMin', 'InexogyAutoBackfillDays', 'InexogyHintCleanup', 'InexogyCleanupDays', 'InexogyCleanupCheck', 'InexogyCleanupRun'] as $f) {
            $this->UpdateFormField($f, 'visible', $isCloud);
        }
        if (!$isCloud) {
            $this->UpdateFormField('InexogyResult', 'visible', false);
            $this->UpdateFormField('InexogyBackfillResult', 'visible', false);
            $this->UpdateFormField('InexogyArchiveStatus', 'visible', false);
        }
        $isGateway = $this->ReadPropertyString('ConnectionMode') === 'gateway';
        $this->UpdateFormField('Host', 'visible', !$isCloud && !$isGateway);
        // Nicht nur ausblenden, auch die Pflicht-Regex entschärfen — falls
        // sie an einem unsichtbaren Feld trotzdem noch griffe.
        $this->UpdateFormField('Host', 'validate', ($isCloud || $isGateway) ? '' : '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$');
        $this->UpdateFormField('Port', 'visible', !$isCloud && !$isGateway);
        $this->UpdateFormField('UnitId', 'visible', !$isCloud && !$isGateway);
        $this->UpdateFormField('ConnectionMode', 'visible', !$isCloud);
        $this->UpdateFormField('ConnectionModeGatewayWarning', 'visible', !$isCloud && $isGateway);
        $this->UpdateFormField('ConnectionModeGatewayUnitIdHint', 'visible', !$isCloud && $isGateway);
        $this->UpdateFormField('BridgeInstanceID', 'visible', !$isCloud && $isGateway);
        $this->UpdateFormField('BridgeStatusLine', 'visible', !$isCloud && $isGateway);
        $this->UpdateFormField('UnitIdAutoLine', 'visible', !$isCloud && $isGateway);
        $this->UpdateFormField('GatewayPick', 'visible', !$isCloud && $isGateway);
        $this->UpdateFormField('BtnCreateBridge', 'visible', !$isCloud && $isGateway);

        // Sollwert-Panel (blue'Log RPC/Power Control) — nur bei den beiden
        // schreibenden Zählertypen sichtbar.
        $isWritable = in_array($meter, self::WRITABLE_METERS, true);
        $this->UpdateFormField('WriteWarningPanel', 'visible', $isWritable);
        $this->UpdateFormField('WriteWarningGridop', 'visible', $meter === 'bluelog_powercontrol');
        foreach (['SetpointMode', 'TargetSetpoint', 'FailsafeMode', 'FailsafeDefaultPercent', 'BtnWriteNow'] as $f) {
            $this->UpdateFormField($f, 'visible', $isWritable);
        }
        $this->UpdateFormField('RpcValidTimeMin', 'visible', in_array($meter, self::RPC_VALID_TIME_METERS, true));
        $single = in_array($meter, self::SINGLE_PHASE_METERS, true);
        foreach (['MeasureMode', 'MeasureModeIntro', 'MeasureModeHint'] as $f) {
            $this->UpdateFormField($f, 'visible', !$single);
        }
        $this->UpdateFormField('MeasureModeSingle', 'visible', $single);
        $this->OnChangeSetpointMode($this->ReadPropertyString('SetpointMode'));
    }

    // Verbindungsweg-Warnung live umschalten (SUITE.md 9j) — dasselbe
    // onChange+UpdateFormField-Muster wie OnChangeMeter(), da PropertyCondition
    // laut Doku nur einen einzelnen Wert kennt, kein Negations-/Array-Fall.
    public function OnChangeConnectionMode(string $connectionMode)
    {
        $isGateway = $connectionMode === 'gateway';
        $this->UpdateFormField('ConnectionModeGatewayWarning', 'visible', $isGateway);
        $this->UpdateFormField('ConnectionModeGatewayUnitIdHint', 'visible', $isGateway);
        $this->UpdateFormField('BridgeInstanceID', 'visible', $isGateway);
        $this->UpdateFormField('BridgeStatusLine', 'visible', $isGateway);
        $this->UpdateFormField('UnitIdAutoLine', 'visible', $isGateway);
        $this->UpdateFormField('GatewayPick', 'visible', $isGateway);
        $this->UpdateFormField('BtnCreateBridge', 'visible', $isGateway);
        // Host/Port/Unit-ID entfallen im Gateway-Modus (ChargerHub-Fund 18.09.2026,
        // SUITE.md 9j): die Unit-ID sitzt dort am DeviceID-Property der
        // Gateway-Instanz hinter der Brücke, nicht an dieser Instanz.
        $this->UpdateFormField('Host', 'visible', !$isGateway);
        $this->UpdateFormField('Host', 'validate', $isGateway ? '' : '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$');
        $this->UpdateFormField('Port', 'visible', !$isGateway);
        $this->UpdateFormField('UnitId', 'visible', !$isGateway);
    }

    /** Feldbeschriftung/Einheit des Zielwert-Felds passend zum Sollwert-Modus. */
    public function OnChangeSetpointMode(string $mode)
    {
        $isAbs = $mode === 'abs';
        $this->UpdateFormField('TargetSetpoint', 'caption', $isAbs ? MHUB_Text::t('Target value (W, absolute)') : MHUB_Text::t('Target value (%, relative)'));
        $this->UpdateFormField('TargetSetpoint', 'suffix', $isAbs ? 'W' : '%');
    }

    // -----------------------------------------------------------------------
    // Treiber-Auswahl
    // -----------------------------------------------------------------------

    private function GetDriver()
    {
        if ($this->driver !== null) {
            return $this->driver;
        }
        $key   = $this->ReadPropertyString('Meter');
        $class = self::DRIVERS[$key] ?? self::DRIVERS['siemens_pac2200'];
        $this->driver = new $class();
        return $this->driver;
    }

    /**
     * Legt die Brücke (MeterHubBridge) an, verbindet sie mit dem gewählten
     * nativen ModBus gateway und trägt sie in das (noch ungespeicherte)
     * Formular ein — Knopf statt automatischer Anlage: GetConfigurationForm()
     * und ApplyChanges() dürfen keine Instanzen erzeugen, ein Klick darf es.
     * Ist am Gateway schon eine Brücke, wird sie wiederverwendet.
     */
    public function CreateBridge(int $gatewayId): string
    {
        if ($gatewayId <= 0 || !IPS_InstanceExists($gatewayId)
            || (IPS_GetInstance($gatewayId)['ModuleInfo']['ModuleID'] ?? '') !== MHUB_ModbusGatewayClient::GATEWAY_GUID) {
            return MHUB_Text::t('❌ Please select this device\'s native "ModBus gateway" first.');
        }
        $bridgeId = 0;
        foreach (IPS_GetInstanceListByModuleID(self::BRIDGE_GUID) as $b) {
            if ((int)(IPS_GetInstance($b)['ConnectionID'] ?? 0) === $gatewayId) {
                $bridgeId = $b;
                break;
            }
        }
        $created = $bridgeId === 0;
        if ($created) {
            $bridgeId = IPS_CreateInstance(self::BRIDGE_GUID);
            IPS_SetName($bridgeId, MHUB_Text::t('MeterHub bridge %s', IPS_GetName($gatewayId)));
            IPS_SetParent($bridgeId, IPS_GetObject($this->InstanceID)['ParentID']);
            IPS_ConnectInstance($bridgeId, $gatewayId);
            IPS_ApplyChanges($bridgeId);
        }
        $this->UpdateFormField('BridgeInstanceID', 'value', $bridgeId);
        $this->UpdateFormField('BridgeStatusLine', 'caption', $this->BridgeStatusLine($bridgeId));
        $this->RefreshUnitIdAutoLine($bridgeId);
        return $created
            ? MHUB_Text::t('✅ Bridge #%d created and connected to "%s". It is entered below — now click "Apply changes".', $bridgeId, IPS_GetName($gatewayId))
            : MHUB_Text::t('✅ Existing bridge #%d reused and connected to "%s". It is entered below — now click "Apply changes".', $bridgeId, IPS_GetName($gatewayId));
    }

    // -----------------------------------------------------------------------
    // Verbund-Verbindungen im Formular sichtbar machen (SUITE.md, verbindlich seit
    // 21.09.2026): je automatischer Verbindung eine LIVE berechnete Statuszeile —
    // ✅ verbunden (Instanz, Name, welche Werte mit Quelle), ⚠️ verbunden, aber
    // nichts Brauchbares, ℹ️ nicht gefunden (was dann gilt), ⛔ Pflichtangabe fehlt.
    // Ein statischer Satz wie „wird automatisch erkannt" sagt nicht, ob es geklappt hat.
    // -----------------------------------------------------------------------

    /** Brücke (Symbox-Gateway-Weg): Instanz, Gateway, Unit-ID und Zustand. */
    private function BridgeStatusLine(int $bridgeId): string
    {
        if ($bridgeId <= 0) {
            return MHUB_Text::t('⛔ No bridge selected — select a ModBus gateway and click "Create bridge and connect". Without a bridge this instance reads nothing.');
        }
        if (!IPS_InstanceExists($bridgeId)) {
            return MHUB_Text::t('⛔ The selected bridge #%d no longer exists — create a new one or choose another. Until then this instance reads nothing.', $bridgeId);
        }
        $bridge = MHUB_Text::t('#%d "%s"', $bridgeId, IPS_GetName($bridgeId));
        if (!function_exists('MHUBB_GetState')) {
            return MHUB_Text::t('⚠️ Bridge %s selected, but the bridge module is not loaded — reload the library in the module management.', $bridge);
        }
        try {
            $st = json_decode((string)MHUBB_GetState($bridgeId), true);
        } catch (\Throwable $e) {
            $st = null;
        }
        if (!is_array($st)) {
            return MHUB_Text::t('⚠️ Bridge %s: state not readable — open the bridge and check it.', $bridge);
        }
        $gid = (int)(IPS_GetInstance($bridgeId)['ConnectionID'] ?? 0);
        $gateway = ($gid > 0 && IPS_InstanceExists($gid)) ? MHUB_Text::t('#%d "%s"', $gid, IPS_GetName($gid)) : '';
        if (empty($st['connected'])) {
            return MHUB_Text::t('⚠️ Bridge %s is not connected to any ModBus gateway — choose the device\'s gateway on the bridge via "Change gateway". Until then this instance reads nothing.', $bridge);
        }
        if (empty($st['parentActive'])) {
            return MHUB_Text::t('⚠️ Bridge %s → ModBus gateway %s is not active (status %d) — check its connection to the device.', $bridge, $gateway, (int)($st['parentStatus'] ?? 0));
        }
        $unit = isset($st['unitId']) && $st['unitId'] !== null ? (string)(int)$st['unitId'] : MHUB_Text::t('not readable');
        return MHUB_Text::t('✅ Bridge %s → ModBus gateway %s active, unit ID %s (source: property "DeviceID" at the gateway).', $bridge, $gateway, $unit);
    }

    /** Wird beim Ändern der Brücken-Auswahl im offenen Formular aufgerufen — die Zeile folgt der Auswahl, nicht erst dem Speicherstand. */
    public function OnChangeBridge(int $bridgeId)
    {
        $this->UpdateFormField('BridgeStatusLine', 'caption', $this->BridgeStatusLine($bridgeId));
        $this->RefreshUnitIdAutoLine($bridgeId);
    }

    /**
     * Unit-ID im Gateway-Weg: kommt automatisch vom ModBus gateway hinter der Brücke und
     * ersetzt dort das Eingabefeld „Unit ID" (SUITE.md „Wert kommt automatisch: Eingabefeld
     * ersetzen", 21.09.2026): das Feld ist ausgeblendet, statt seiner steht diese
     * schreibgeschützte Zeile. Der Wert wird bewusst NICHT in die Property „UnitId"
     * geschrieben — er soll dem Gateway folgen, nicht als eigene Eingabe erstarren.
     * 🔗 automatisch übernommen, ℹ️ nichts verfügbar.
     */
    /** Grün (SUITE.md: 🔗 = automatisch übernommen, Label-Farbe 0x2E8B3D), sonst Standardfarbe (-1). */
    private static function AutoLineColor(string $caption): int
    {
        return str_starts_with($caption, '🔗') ? 0x2E8B3D : -1;
    }

    /** Text und Farbe der Zeile zusammen setzen — die Farbe folgt dem Zustand, nicht nur dem Öffnen des Formulars. */
    private function RefreshUnitIdAutoLine(int $bridgeId): void
    {
        $line = $this->UnitIdAutoLine($bridgeId);
        $this->UpdateFormField('UnitIdAutoLine', 'caption', $line);
        $this->UpdateFormField('UnitIdAutoLine', 'color', self::AutoLineColor($line));
    }

    private function UnitIdAutoLine(int $bridgeId): string
    {
        $unit = null;
        if ($bridgeId > 0 && IPS_InstanceExists($bridgeId) && function_exists('MHUBB_GetState')) {
            try {
                $st = json_decode((string)MHUBB_GetState($bridgeId), true);
            } catch (\Throwable $e) {
                $st = null;
            }
            if (is_array($st) && isset($st['unitId']) && $st['unitId'] !== null) {
                $unit = (int)$st['unitId'];
            }
        }
        if ($unit === null) {
            return MHUB_Text::t('ℹ️ Unit ID: not available yet — it comes automatically from the ModBus gateway as soon as a bridge connected to a gateway is selected.');
        }
        $gid = (int)(IPS_GetInstance($bridgeId)['ConnectionID'] ?? 0);
        if ($gid > 0 && IPS_InstanceExists($gid)) {
            return MHUB_Text::t('🔗 Unit ID: %s (automatically from ModBus gateway %s, property "DeviceID").', $unit, MHUB_Text::t('#%d "%s"', $gid, IPS_GetName($gid)));
        }
        return MHUB_Text::t('🔗 Unit ID: %s (automatically from the ModBus gateway, property "DeviceID").', $unit);
    }

    /**
     * Vom Inexogy-Treiber nach jeder Abfrage aufgerufen: lehnt Inexogy den
     * gespeicherten Zugriffsschlüssel ab (HTTP 401)? Schreibt nur bei einem
     * Wechsel (kein Attribut-Schreiben je Abfragetakt) und protokolliert den
     * Wechsel auf „abgelehnt" einmal im Systemprotokoll.
     */
    public function NoteInexogyAuth(bool $rejected)
    {
        if ($this->ReadAttributeBoolean('InexogyAuthRejected') !== $rejected) {
            $this->WriteAttributeBoolean('InexogyAuthRejected', $rejected);
            if ($rejected) {
                $this->LogMessage($this->SecretsConfigured()
                    ? MHUB_Text::t('Inexogy rejects the stored access token (HTTP 401) — automatic re-login from the vault will be attempted.')
                    : MHUB_Text::t('Inexogy rejects the stored access token (HTTP 401) — log in again in the instance form under "Cloud access".') . $this->SecretsTip(), KL_WARNING);
            }
            $this->UpdateFormField('InexogyStatusLine', 'caption', $this->InexogyStatusLine());
        }
        // Bei jeder weiter abgelehnten Abfrage erneut prüfen — MaybeAutoRelogin()
        // hält selbst den Abstand zwischen zwei Versuchen ein.
        if ($rejected) {
            $this->MaybeAutoRelogin();
        }
    }

    /** Gibt es einen nutzbaren Tresor (gewählt oder selbst erkannt)? Für Log-Texte und das Auslösen der Automatik. */
    private function SecretsConfigured(): bool
    {
        return $this->ResolveSecrets()[0] > 0;
    }

    /**
     * Welchen Tresor nutzt MeterHub für die Neuanmeldung? Rückgabe
     * [Instanz-ID (0 = keiner), Herkunft 'gewählt'|'automatisch'|'', Grund bei keinem, Detail].
     * Grundsatz (Dietmar 06.10.2026): MeterHub erkennt selbst, ob ein Tresor da ist und das
     * Inexogy-Passwort enthält — wenn nicht, gilt der normale Weg ohne Tresor. Eine
     * AUSDRÜCKLICHE Auswahl gewinnt immer und wird nicht still durch einen anderen Tresor
     * ersetzt (ist sie kaputt, meldet das die Statuszeile). Ohne Auswahl wird nur dann
     * automatisch genutzt, wenn GENAU EIN Tresor Eintrag und Feld mit Inhalt hat — bei
     * mehreren wird nicht geraten. Gründe: off | nomodule | noinstance | noentry | multiple.
     */
    private function ResolveSecrets(?bool $enabled = null, ?int $explicit = null, ?string $record = null, ?string $passField = null): array
    {
        $enabled  = $enabled ?? $this->ReadPropertyBoolean('SecretsEnabled');
        $explicit = $explicit ?? $this->ReadPropertyInteger('SecretsInstanceID');
        if (!$enabled) {
            return [0, '', 'off', ''];
        }
        if ($explicit > 0) {
            return [$explicit, MHUB_Text::t('selected'), '', ''];
        }
        if (!function_exists('SEC_GetSecret')) {
            return [0, '', 'nomodule', ''];
        }
        $instances = $this->SecretsInstances();
        if (!$instances) {
            return [0, '', 'noinstance', ''];
        }
        $record    = $record ?? $this->ReadPropertyString('SecretsRecord');
        $passField = $passField ?? $this->ReadPropertyString('SecretsPassField');
        $ok = [];
        foreach ($instances as $iid => $name) {
            [$v, ] = $this->ReadSecretField(trim($passField), $iid, $record);
            if ($v !== null) {
                $ok[$iid] = $name;
            }
        }
        if (count($ok) === 1) {
            return [(int)array_key_first($ok), 'automatic', '', ''];
        }
        $list = function (array $m): string {
            $o = [];
            foreach ($m as $iid => $nm) {
                $o[] = '#' . $iid . ' „' . $nm . '"';
            }
            return implode(', ', $o);
        };
        return $ok ? [0, '', 'multiple', $list($ok)] : [0, '', 'noentry', $list($instances)];
    }

    /** Instanzen des Tresor-Moduls (Modul-Präfix „SEC", wie SymconSecrets es führt): [ID => Name]. Leer, wenn das Modul fehlt. */
    private function SecretsInstances(): array
    {
        $out = [];
        if (!function_exists('IPS_GetModuleList') || !function_exists('IPS_GetModule')) {
            return $out;
        }
        foreach (IPS_GetModuleList() as $guid) {
            $m = @IPS_GetModule($guid);
            if (is_array($m) && ($m['Prefix'] ?? '') === 'SEC') {
                foreach (IPS_GetInstanceListByModuleID($guid) as $iid) {
                    $out[(int)$iid] = IPS_GetName((int)$iid);
                }
            }
        }
        return $out;
    }

    /** Gehört die Instanz zum Tresor-Modul? (Die Auswahl im Formular zeigt alle Instanzen.) */
    private function IsSecretsInstance(int $id): bool
    {
        $guid = (string)(IPS_GetInstance($id)['ModuleInfo']['ModuleID'] ?? '');
        $m = $guid === '' ? null : @IPS_GetModule($guid);
        return is_array($m) && ($m['Prefix'] ?? '') === 'SEC';
    }

    /**
     * Liest ein Feld aus dem Tresor (SymconSecrets). Der Tresor liefert für einen
     * Eintrag ein JSON-Objekt aller Felder (`SEC_GetSecret($id, 'Inexogy')` →
     * {"User":…,"Pass":…}) — live bei Dietmar so gemessen, der in der README
     * genannte Feldpfad `Eintrag/Feld` lieferte dort nichts. Deshalb den ganzen
     * Eintrag lesen und das Feld selbst herausziehen; ist die Antwort kein JSON,
     * zählt sie als der Wert selbst (Eintrag direkt auf ein Blatt gerichtet).
     * Rückgabe [Wert|null, Fehlertext]. Der Wert wird nie protokolliert.
     */
    private function ReadSecretField(string $field, ?int $id = null, ?string $record = null): array
    {
        $id = $id ?? $this->ResolveSecrets()[0];
        if ($id <= 0) {
            return [null, MHUB_Text::t('no vault selected')];
        }
        if (!function_exists('SEC_GetSecret')) {
            return [null, MHUB_Text::t('the vault module (SymconSecrets) is not loaded')];
        }
        if (!IPS_InstanceExists($id)) {
            return [null, MHUB_Text::t('the selected vault instance #%d no longer exists', $id)];
        }
        if (!$this->IsSecretsInstance($id)) {
            return [null, MHUB_Text::t('the selected instance #%d is not a vault (SymconSecrets)', $id)];
        }
        $record = trim($record ?? $this->ReadPropertyString('SecretsRecord'));
        if ($record === '' || $field === '') {
            return [null, MHUB_Text::t('entry or field name not specified')];
        }
        try {
            $raw = @SEC_GetSecret($id, $record);
        } catch (\Throwable $e) {
            return [null, MHUB_Text::t('vault not readable')];
        }
        if ($raw === false || $raw === null || $raw === '') {
            return [null, MHUB_Text::t('entry "%s" not found in the vault', $record)];
        }
        $j = json_decode((string)$raw, true);
        if (is_array($j)) {
            $v = $j[$field] ?? null;
            if (!is_string($v) && !is_numeric($v)) {
                return [null, MHUB_Text::t('field "%s" is missing in entry "%s" (available: %s)', $field, $record, implode(', ', array_map('strval', array_keys($j))))];
            }
            $v = (string)$v;
            return $v === '' ? [null, MHUB_Text::t('field "%s" is empty', $field)] : [$v, ''];
        }
        return [(string)$raw, ''];
    }

    /** Wie viele Sekunden mindestens zwischen zwei automatischen Anmeldeversuchen liegen (wächst bei Fehlversuchen, Obergrenze 6 h). */
    private function AutoReloginCooldown(): int
    {
        $fails = max(0, $this->ReadAttributeInteger('InexogyAutoReloginFails'));
        return (int)min(21600, 900 * (2 ** min($fails, 5)));
    }

    /**
     * Meldet sich bei abgelehntem Zugriffsschlüssel selbst neu an, wenn ein
     * Tresor gewählt ist. Abstand: mindestens 15 Minuten, nach Fehlversuchen
     * länger (Obergrenze 6 h) — ein falsches Passwort soll das Inexogy-Konto
     * nicht mit Anmeldeversuchen überhäufen. E-Mail aus der Instanz, ersatzweise
     * aus dem Tresor; das Passwort liegt nur für die Dauer der Anmeldung im
     * Arbeitsspeicher und taucht in keinem Protokoll und keinem Attribut auf.
     */
    private function MaybeAutoRelogin(): void
    {
        if (!in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true)) {
            return;
        }
        // Erst die billige Wartezeit-Prüfung, dann die Tresor-Suche (die liest ggf. Einträge) —
        // diese Funktion läuft bei abgelehntem Schlüssel in jedem Abfragetakt.
        if ((time() - $this->ReadAttributeInteger('InexogyAutoReloginLastTs')) < $this->AutoReloginCooldown()) {
            return;
        }
        if (!$this->SecretsConfigured()) {
            return;
        }
        // Vor dem Versuch merken (nicht danach): ein Absturz mitten drin darf
        // nicht zu Sofort-Wiederholungen führen.
        $this->WriteAttributeInteger('InexogyAutoReloginLastTs', time());
        $stamp = date('d.m.Y H:i');
        $fail = function (string $why) use ($stamp): void {
            $this->WriteAttributeInteger('InexogyAutoReloginFails', $this->ReadAttributeInteger('InexogyAutoReloginFails') + 1);
            $this->WriteAttributeString('InexogyAutoReloginInfo', MHUB_Text::t('%s — failed: %s', $stamp, $why));
            $this->LogMessage(MHUB_Text::t('Automatic Inexogy re-login failed — %s', $why), KL_WARNING);
            $this->UpdateFormField('SecretsStatusLine', 'caption', $this->SecretsStatusLine());
        };
        [$pass, $err] = $this->ReadSecretField(trim($this->ReadPropertyString('SecretsPassField')));
        if ($pass === null) {
            $fail(MHUB_Text::t('password not readable (%s)', $err));
            return;
        }
        $email = trim($this->ReadPropertyString('InexogyEmail'));
        if ($email === '') {
            [$mail, ] = $this->ReadSecretField(trim($this->ReadPropertyString('SecretsUserField')));
            $email = (string)$mail;
        }
        if ($email === '') {
            $fail(MHUB_Text::t('no e-mail address (neither in the instance nor in the vault)'));
            return;
        }
        $c = $this->InexogyHandshake($email, $pass, $why);
        $pass = '';
        if ($c === null) {
            $fail($why);
            return;
        }
        $this->StoreInexogyTokens($c);
        $this->WriteAttributeInteger('InexogyAutoReloginFails', 0);
        $this->WriteAttributeString('InexogyAutoReloginInfo', MHUB_Text::t('%s — logged in again successfully', $stamp));
        $this->LogMessage(MHUB_Text::t('Automatically logged in to Inexogy again (access token renewed from the vault).'), KL_MESSAGE);
        $this->UpdateFormField('InexogyStatusLine', 'caption', $this->InexogyStatusLine());
        $this->UpdateFormField('SecretsStatusLine', 'caption', $this->SecretsStatusLine());
    }

    /**
     * Hinweis auf die Tresor-Möglichkeit, wo sie gerade nützt (abgelehnter Zugriffsschlüssel, kein
     * Tresor in Benutzung). Leer, wenn der Nutzer die Tresor-Nutzung bewusst abgeschaltet hat —
     * dann wird nicht weiter geworben.
     */
    private function SecretsTip(): string
    {
        if (!$this->ReadPropertyBoolean('SecretsEnabled')) {
            return '';
        }
        return MHUB_Text::t(' Tip: with a vault (community module "SymconSecrets") MeterHub will log in again by itself in future — the password stays outside MeterHub; setup under "Automatic re-login" further below.');
    }

    /** Stand der automatischen Neuanmeldung für die 401-Zeile, wenn ein Tresor in Benutzung ist. */
    private function AutoReloginHint(): string
    {
        $info = $this->ReadAttributeString('InexogyAutoReloginInfo');
        return MHUB_Text::t(' The automatic re-login from the vault is running (no more often than every 15 minutes)') . ($info !== '' ? MHUB_Text::t(' — last attempt: %s', $info) : '') . '.';
    }

    /** Statuszeile zur Tresor-Anbindung (SUITE.md: je Verbindung eine live berechnete Zeile). */
    private function SecretsStatusLine(?bool $enabled = null, ?int $instanceId = null, ?string $record = null, ?string $passField = null): string
    {
        // Eingabe aus dem offenen Formular (noch ungespeichert) hat Vorrang vor dem Speicherstand.
        $rec = trim($record ?? $this->ReadPropertyString('SecretsRecord'));
        $fld = trim($passField ?? $this->ReadPropertyString('SecretsPassField'));
        [$id, $how, $why, $detail] = $this->ResolveSecrets($enabled, $instanceId, $rec, $fld);
        $normal = MHUB_Text::t(' — the normal way applies: if the access token is rejected, log in again by hand ("Log in and fetch meters").');
        if ($id <= 0) {
            switch ($why) {
                case 'off':
                    return MHUB_Text::t('ℹ️ Vault use switched off') . $normal;
                case 'nomodule':
                    return MHUB_Text::t('ℹ️ No vault found: the vault module "SymconSecrets" is not installed (Module Store)') . $normal . MHUB_Text::t(' It is only needed if MeterHub is to log in again by itself when the key becomes invalid.');
                case 'noinstance':
                    return MHUB_Text::t('ℹ️ No vault found: the vault module "SymconSecrets" is installed, but there is no instance of it yet') . $normal . MHUB_Text::t(' To use it, create an instance and store a password in it — MeterHub then detects it by itself.');
                case 'noentry':
                    return MHUB_Text::t('ℹ️ Vault present (%s), but without entry "%s" with a filled field "%s"', $detail, $rec, $fld) . $normal . MHUB_Text::t(' If you create the entry, MeterHub detects it by itself.');
                case 'multiple':
                    return MHUB_Text::t('⚠️ Several vaults contain entry "%s" with field "%s" (%s) — MeterHub does not guess, please choose the one you want above', $rec, $fld, $detail) . $normal;
            }
            return MHUB_Text::t('ℹ️ No vault in use') . $normal;
        }
        if (!function_exists('SEC_GetSecret')) {
            return MHUB_Text::t('⚠️ Vault #%d selected, but the vault module (SymconSecrets) is not loaded — install it in the module management', $id) . $normal;
        }
        if (!IPS_InstanceExists($id)) {
            return MHUB_Text::t('⚠️ The selected vault instance #%d no longer exists — choose again', $id) . $normal;
        }
        if (!$this->IsSecretsInstance($id)) {
            return MHUB_Text::t('⚠️ The selected instance #%d "%s" is not a vault (SymconSecrets) — choose the instance of the vault module', $id, IPS_GetName($id)) . $normal;
        }
        $name = MHUB_Text::t('#%d "%s"', $id, IPS_GetName($id));
        try {
            $raw = @SEC_GetSecret($id, $rec);
        } catch (\Throwable $e) {
            $raw = null;
        }
        if ($raw === false || $raw === null || $raw === '') {
            return MHUB_Text::t('⚠️ Vault %s: entry "%s" not found — check the name in the vault', $name, $rec) . $normal;
        }
        $j = json_decode((string)$raw, true);
        if (is_array($j)) {
            $v = $j[$fld] ?? null;
            if (!is_string($v) && !is_numeric($v)) {
                return MHUB_Text::t('⚠️ Vault %s: entry "%s" has no field "%s" (available: %s)', $name, $rec, $fld, implode(', ', array_map('strval', array_keys($j)))) . $normal;
            }
            $len = strlen((string)$v);
        } else {
            $len = strlen((string)$raw);
        }
        if ($len === 0) {
            return MHUB_Text::t('⚠️ Vault %s: field "%s" in entry "%s" is empty', $name, $fld, $rec) . $normal;
        }
        $info = $this->ReadAttributeString('InexogyAutoReloginInfo');
        return ($how === 'automatic'
                ? MHUB_Text::t('✅ Vault detected automatically: %s: password found (entry "%s", field "%s", %d characters, only the length is shown) — if the access token is rejected, MeterHub logs in again automatically (no more often than every 15 minutes).', $name, $rec, $fld, $len)
                : MHUB_Text::t('✅ Vault %s: password found (entry "%s", field "%s", %d characters, only the length is shown) — if the access token is rejected, MeterHub logs in again automatically (no more often than every 15 minutes).', $name, $rec, $fld, $len))
            . ($info !== '' ? MHUB_Text::t(' Last attempt: %s.', $info) : '');
    }

    /** Wird beim Ändern der Tresor-Felder im offenen Formular aufgerufen — die Zeile folgt der Eingabe, nicht dem Speicherstand. */
    public function OnChangeSecrets(bool $enabled, int $instanceId, string $record, string $passField)
    {
        $this->UpdateFormField('SecretsStatusLine', 'caption', $this->SecretsStatusLine($enabled, $instanceId, $record, $passField));
    }

    /** Factory für den Inexogy-Client des Handshakes (in Prüfständen ersetzbar, damit kein Netzzugriff nötig ist). */
    protected function NewInexogyClient()
    {
        return new MHUB_InexogyClient();
    }

    /**
     * Die vier Handshake-Schritte. Rückgabe: der Client mit gültigen Tokens
     * oder null; $why trägt dann den deutschen Fehltext (mit Schritt-Nummer).
     * E-Mail und Passwort werden nur durchgereicht, nie gespeichert oder protokolliert.
     */
    private function InexogyHandshake(string $email, string $pass, ?string &$why)
    {
        $why = null;
        $c = $this->NewInexogyClient();
        if (!$c->registerConsumer('IP-Symcon MeterHub ' . $this->InstanceID)) {
            $why = MHUB_Text::t('Login failed during registration (step 1/4). Is the Inexogy API reachable? (%s)', $c->getLastError());
            return null;
        }
        if (!$c->fetchRequestToken()) {
            $why = MHUB_Text::t('Login failed while requesting the request token (step 2/4). (%s)', $c->getLastError());
            return null;
        }
        $verifier = $c->authorize($email, $pass);
        if ($verifier === '') {
            $why = MHUB_Text::t('Login failed during authorization (step 3/4). Wrong e-mail or password? (%s)', $c->getLastError());
            return null;
        }
        if (!$c->fetchAccessToken($verifier)) {
            $why = MHUB_Text::t('Login failed while requesting the access token (step 4/4). (%s)', $c->getLastError());
            return null;
        }
        return $c;
    }

    /** Tokens eines erfolgreichen Handshakes sichern (Attribute, kein ApplyChanges nötig) und „abgelehnt" zurücknehmen. */
    private function StoreInexogyTokens($c): void
    {
        $this->WriteAttributeString('InexogyConsumerKey',    $c->getConsumerKey());
        $this->WriteAttributeString('InexogyConsumerSecret', $c->getConsumerSecret());
        $this->WriteAttributeString('InexogyToken',          $c->getToken());
        $this->WriteAttributeString('InexogyTokenSecret',    $c->getTokenSecret());
        $this->WriteAttributeBoolean('InexogyAuthRejected', false);
    }

    /** Inexogy (Cloud): angemeldet? Zähler-UID? letzte Abfrage? aktueller Wert samt Quelle. */
    private function InexogyStatusLine(): string
    {
        if ($this->ReadAttributeString('InexogyToken') === '') {
            return MHUB_Text::t('⛔ Not logged in yet — enter the e-mail and password of the Inexogy account and click "Log in and fetch meters". Without a login this instance reads nothing.');
        }
        $uid = $this->ReadPropertyString('InexogyMeterID');
        if ($uid === '') {
            return MHUB_Text::t('⚠️ Logged in to Inexogy (access token stored), but no meter UID selected yet — select a meter below and apply.');
        }
        if ($this->ReadAttributeBoolean('InexogyAuthRejected')) {
            return MHUB_Text::t('⚠️ Inexogy rejects the stored access token (HTTP 401) — according to the Inexogy documentation it can become invalid, and the login must then be repeated. Enter e-mail and password below, "Apply changes", then "Log in and fetch meters". Until then this instance reads nothing (meter UID %s stays stored).', $uid) . ($this->SecretsConfigured() ? $this->AutoReloginHint() : $this->SecretsTip());
        }
        $inst = function_exists('IPS_GetInstance') ? @IPS_GetInstance($this->InstanceID) : [];
        $status = (int)($inst['InstanceStatus'] ?? 0);
        $tail = $status === 201
            ? MHUB_Text::t(' The last query failed — check access, meter UID and internet connection.')
            : ($status === 102 ? MHUB_Text::t(' The last query was successful.') : MHUB_Text::t(' No query evaluated yet.'));
        $pid = $this->FindVarByIdent('power_total');
        $value = $pid ? MHUB_Text::t(' Current power: %d W (source: Inexogy meter %s).', round((float)GetValue($pid)), $uid) : '';
        return ($status === 201 ? '⚠️' : '✅') . MHUB_Text::t(' Logged in to Inexogy (access token stored), meter UID %s (source: selection below).', $uid) . $value . $tail;
    }

    /** Archiv (Archive Control): findet MeterHub eines, und was gilt sonst? */
    private function ArchiveStatusLine(): string
    {
        $acs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (!$acs) {
            return MHUB_Text::t('ℹ️ No archive (Archive Control) found — the values of this instance are not recorded, the compaction below does not apply. Create an archive instance, then "Apply".');
        }
        $ac = (int)$acs[0];
        $more = count($acs) > 1 ? MHUB_Text::t(' (the first of %d archives is used)', count($acs)) : '';
        return ($more !== '' ? '⚠️' : '✅') . MHUB_Text::t(' Archive #%d "%s" found%s — power and energy values of this instance are recorded there and compacted according to the settings below.', $ac, IPS_GetName($ac), $more);
    }

    /** Grund des letzten Fehlschlags über die Brücke (no_bridge/not_connected/parent_inactive/no_response), für die Statustexte. */
    private ?string $lastBridgeError = null;

    /**
     * Schickt einen Request über die Brücke (MeterHubBridge) und liefert die
     * rohe Antwort — leer bei jedem Fehler, der Grund landet in $error.
     * Die Brücke antwortet immer als JSON mit base64-kodierten Daten: rohe
     * Registerbytes sind meist kein gültiges UTF-8 und sollen die
     * Instanzgrenze unbeschadet überstehen. Hinter function_exists(), damit
     * MeterHub ohne die Brücke unverändert läuft (Verbund-Grundregel).
     */
    private static function BridgeCall(int $bridgeId, string $json, ?string &$error): string
    {
        $error = null;
        if ($bridgeId <= 0 || !IPS_InstanceExists($bridgeId) || !function_exists('MHUBB_Forward')) {
            $error = 'no_bridge';
            return '';
        }
        $r = json_decode((string)MHUBB_Forward($bridgeId, $json), true);
        if (!is_array($r) || empty($r['ok'])) {
            $error = is_array($r) ? (string)($r['error'] ?? 'no_response') : 'no_response';
            return '';
        }
        $raw = base64_decode((string)($r['data'] ?? ''), true);
        if ($raw === false || $raw === '') {
            $error = 'no_response';
            return '';
        }
        return $raw;
    }

    /** Erklärender Text zum letzten Brücken-Fehlschlag. */
    private function BridgeErrorText(): string
    {
        switch ($this->lastBridgeError) {
            case 'no_bridge':
                return MHUB_Text::t('No bridge selected or the selected instance no longer exists — select the MeterHub bridge under "Connection".');
            case 'not_connected':
                return MHUB_Text::t('The selected bridge is not connected to any ModBus gateway — choose the matching gateway on the bridge via "Change gateway".');
            case 'parent_inactive':
                return MHUB_Text::t('The bridge\'s gateway is not active — check its connection to the device.');
            default:
                return MHUB_Text::t('The gateway does not answer — does the unit ID (property "DeviceID" at the gateway) match the device\'s address?');
        }
    }

    private function GetModbusClient(): MHUB_ModbusClientInterface
    {
        if ($this->ReadPropertyString('ConnectionMode') === 'gateway') {
            $bridgeId = $this->ReadPropertyInteger('BridgeInstanceID');
            $mb = new MHUB_ModbusGatewayClient(
                $this->ReadPropertyInteger('UnitId'),
                function (string $json) use ($bridgeId): string {
                    return self::BridgeCall($bridgeId, $json, $this->lastBridgeError);
                }
            );
        } else {
            $mb = new MHUB_ModbusTcpClient(
                $this->ReadPropertyString('Host'),
                $this->ReadPropertyInteger('Port'),
                $this->ReadPropertyInteger('UnitId')
            );
        }
        $mb->setWordSwap($this->ReadPropertyBoolean('WordSwap'));
        return $mb;
    }

    /**
     * Transport passend zum Zählertyp: für Cloud-Zähler der MHUB_InexogyClient (aus
     * den gespeicherten Tokens), sonst der Modbus-Client. Der Treiber bekommt
     * diesen als ersten Parameter und weiß, wie er ihn benutzt.
     */
    private function GetTransport()
    {
        if (in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true)) {
            return new MHUB_InexogyClient(
                $this->ReadAttributeString('InexogyConsumerKey'),
                $this->ReadAttributeString('InexogyConsumerSecret'),
                $this->ReadAttributeString('InexogyToken'),
                $this->ReadAttributeString('InexogyTokenSecret')
            );
        }
        return $this->GetModbusClient();
    }

    /** Für den Inexogy-Treiber: die konfigurierte Zähler-UID. */
    public function InexogyMeterId(): string
    {
        return $this->ReadPropertyString('InexogyMeterID');
    }

    // Für die schreibenden blue'Log-Treiber (MHUB_WritableMeterDriverInterface)
    // — öffentliche Wrapper nach demselben Muster wie InexogyMeterId() oben,
    // da Treiberklassen nur auf PUBLIC Methoden von $hub zugreifen können.
    public function SetpointMode(): string        { return $this->ReadPropertyString('SetpointMode'); }
    public function TargetSetpoint(): float       { return $this->ReadPropertyFloat('TargetSetpoint'); }
    public function FailsafeMode(): string         { return $this->ReadPropertyString('FailsafeMode'); }
    public function FailsafeDefaultPercent(): float{ return $this->ReadPropertyFloat('FailsafeDefaultPercent'); }
    public function RpcValidTimeMin(): int         { return $this->ReadPropertyInteger('RpcValidTimeMin'); }

    /**
     * Führt den OAuth-Handshake mit E-Mail+Passwort aus, speichert danach NUR
     * die Tokens (Attribute) und leert das Passwort-Property. Anschließend wird
     * die Zählerliste des Kontos geholt und als Auswahl angeboten. Rückgabe-
     * texte gehen nur ins Formularfeld — Passwort/Token nie in Log/Anzeige.
     *
     * Reihenfolge bewusst so: ALLE UpdateFormField()-Aufrufe (Ergebnistext,
     * Zählerliste) laufen VOR `IPS_SetProperty()`+`IPS_ApplyChanges()`, das
     * Löschen des Passwort-Property steht ganz am Ende. Anlass: HeishaMon-Fund
     * im Symcon-Store-Review 23.09.2026, Reviewer-Zitat „Bei ApplyChanges wird
     * das Formular neu geladen und die UpdateFormField gehen verloren" — genau
     * dieser Fund traf hier zu: `IPS_ApplyChanges()` stand bisher VOR den
     * UpdateFormField()-Aufrufen, die dadurch wirkungslos blieben (bestätigt
     * durch die offizielle Doku: `IPS_SetProperty()` plant den Wert nur, erst
     * `IPS_ApplyChanges()` aktiviert ihn — ganz weglassen wie bei einem reinen
     * Formular-Vorschlag verbietet sich hier also, das Passwort MUSS sofort
     * wirklich gelöscht werden, siehe Zugangsdaten-Konvention oben). Die
     * gefundene Zählerliste zusätzlich als Attribut `InexogyMeterOptions`
     * gecacht (GetConfigurationForm() liest es), damit sie auch den durch
     * `IPS_ApplyChanges()` ausgelösten Formular-Reload übersteht.
     */
    public function InexogyLogin()
    {
        // Zusätzlich ins Systemprotokoll: die Meldung im Formular verschwindet
        // mit dem Schließen der Maske, ist also für eine spätere Fehlersuche
        // (Fernwartung, andere Sitzung) nicht mehr auffindbar.
        $say = function (string $m) {
            $this->UpdateFormField('InexogyResult', 'caption', $m);
            $this->UpdateFormField('InexogyResult', 'visible', true);
            trigger_error('InexogyLogin #' . $this->InstanceID . ': ' . $m, E_USER_NOTICE);
        };
        $email = trim($this->ReadPropertyString('InexogyEmail'));
        $pass  = (string)$this->ReadPropertyString('InexogyPassword');
        // Ist ein Tresor gewählt, genügt dort das Passwort — Feld hier leer lassen.
        $fromVault = false;
        if ($pass === '' && $this->SecretsConfigured()) {
            [$vp, ] = $this->ReadSecretField(trim($this->ReadPropertyString('SecretsPassField')));
            if ($vp !== null) {
                $pass = $vp;
                $fromVault = true;
            }
        }
        if ($email === '' && $fromVault) {
            [$vm, ] = $this->ReadSecretField(trim($this->ReadPropertyString('SecretsUserField')));
            $email = (string)$vm;
        }
        if ($email === '' || $pass === '') {
            $say(MHUB_Text::t('❌ Please enter e-mail and password first and apply, then log in — or store a password in the vault and select the vault below.'));
            return;
        }

        $c = $this->InexogyHandshake($email, $pass, $why);
        $pass = '';
        if ($c === null) {
            $say('❌ ' . $why);
            return;
        }

        // Erfolg: Tokens sichern (Attribute, unproblematisch — kein ApplyChanges nötig).
        $this->StoreInexogyTokens($c);
        // Eine von Hand geglückte Anmeldung beendet auch die Wartezeit der automatischen Versuche.
        $this->WriteAttributeInteger('InexogyAutoReloginFails', 0);

        $meters = $c->getMeters();
        $opts = [];
        if (!$meters) {
            $say(MHUB_Text::t('✅ Logged in, tokens stored, password discarded. However, no meters were found.'));
        } else {
            $lines = [MHUB_Text::t('✅ Logged in, tokens stored, password discarded. Meters found:')];
            foreach ($meters as $m) {
                $uid  = (string)($m['meterId'] ?? '');
                $sn   = (string)($m['serialNumber'] ?? ($m['fullSerialNumber'] ?? ''));
                $type = (string)($m['type'] ?? ($m['measurementType'] ?? ''));
                if ($uid === '') { continue; }
                $lines[] = '   • ' . ($sn !== '' ? $sn : $uid) . ($type !== '' ? " ($type)" : '');
                $opts[]  = ['caption' => ($sn !== '' ? $sn : $uid) . ($type !== '' ? " — $type" : ''), 'value' => $uid];
            }
            $lines[] = MHUB_Text::t('Please select the meter UID below and apply.');
            $this->WriteAttributeString('InexogyMeterOptions', (string)json_encode($opts));
            $this->UpdateFormField('InexogyMeterID', 'options', json_encode($opts));
            $say(implode("\n", $lines));
        }

        // Ganz am Ende: Passwort-Property wirklich löschen. IPS_SetProperty()
        // allein plant den Wert nur — erst IPS_ApplyChanges() aktiviert ihn
        // (offizielle Doku), das Löschen braucht also beides. Der dadurch
        // ausgelöste Formular-Reload zeigt Passwortfeld und Statuszeile danach
        // ohnehin korrekt (beide werden in GetConfigurationForm() frisch aus
        // Property/Attribut berechnet) — eigene UpdateFormField()-Aufrufe dafür
        // wären wirkungslose Last, siehe Funktionskommentar.
        if ($this->ReadPropertyString('InexogyPassword') !== '') {
            IPS_SetProperty($this->InstanceID, 'InexogyPassword', '');
            IPS_ApplyChanges($this->InstanceID);
        }
    }

    /**
     * Einmalige Diagnose (29.07.2026): prüft live, ob /readings kumulative
     * Zählerstände liefert (wie /last_reading) oder Intervalldeltas —
     * entscheidet, wie ein künftiger Lastgang-Archiv-Nachtrag rechnen
     * muss. Nur für Inexogy-Instanzen mit vorhandenem Token. Meldet über
     * trigger_error, keine Änderung an Formular/Konfiguration.
     */
    public function DiagnoseInexogyReadings()
    {
        if (!in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true)) {
            trigger_error(MHUB_Text::t('DiagnoseInexogyReadings: no Inexogy instance.'), E_USER_WARNING);
            return;
        }
        $c = $this->GetTransport();
        $to = time() * 1000;
        // Bewusst weiter als "letzte paar Stunden" gefasst — falls die
        // API erst mit Verzögerung berichtet oder ein schmales Fenster
        // selbst schon leer/fehlerhaft beantwortet wird.
        $from = $to - 48 * 3600 * 1000;
        $readings = $c->getReadings($this->ReadPropertyString('InexogyMeterID'), $from, $to, 'fifteen_minutes');
        $out = ['count=' . count($readings) . ' letzterFehler=' . $c->getLastError()];
        foreach (array_slice($readings, 0, 4) as $r) {
            $t = date('H:i', (int)(($r['time'] ?? 0) / 1000));
            $e = $r['values']['energy'] ?? null;
            $eo = $r['values']['energyOut'] ?? null;
            $p = $r['values']['power'] ?? null;
            $out[] = "$t energy=" . var_export($e, true) . " energyOut=" . var_export($eo, true) . " power=" . var_export($p, true);
        }
        trigger_error("DIAGNOSE-INEXOGY-READINGS\n" . implode("\n", $out), E_USER_WARNING);
    }

    // Bewusst in Blöcken statt einem einzigen Abruf über den ganzen
    // Zeitraum: hält jede /readings-Anfrage handhabbar UND bleibt sicher
    // unter dem dokumentierten 10.000er-Limit von AC_GetLoggedValues()
    // je Aufruf, das sonst bei mehrmonatigen Rückständen (Dietmars
    // Anwendungsfall "seit Jahresanfang") den Dopplungsschutz unbemerkt
    // hätte lückenhaft werden lassen können.
    private const INEXOGY_BACKFILL_CHUNK_DAYS = 30;

    /**
     * Trägt den Inexogy-Lastgang rückwirkend ins Symcon-Archiv der
     * vorhandenen energy_import/energy_export/power_total-Variablen
     * nach — Dietmars Anlass: Inexogy ist sein Abrechnungszähler,
     * der laufende Abfragetakt reicht nicht für eine Rechnungskontrolle.
     * Werte sind live gegengeprüft kumulativ (wie /last_reading; die
     * Differenz zweier Nachbarwerte reproduziert exakt das separat
     * gemeldete power-Feld, siehe MHUB_DiagnoseInexogyReadings()) —
     * werden also als normale archivierte Zählerstände eingetragen,
     * kein Delta-Rechnen nötig.
     */
    public function BackfillInexogyArchive()
    {
        $days = max(1, $this->ReadPropertyInteger('InexogyBackfillDays'));
        $m    = $this->DoBackfillInexogyArchive(time() - $days * 86400, time());
        $this->UpdateFormField('InexogyBackfillResult', 'caption', $m);
        $this->UpdateFormField('InexogyBackfillResult', 'visible', true);
        $this->UpdateInexogyArchiveStatusField();
        trigger_error('BackfillInexogyArchive #' . $this->InstanceID . ': ' . $m, E_USER_NOTICE);
    }

    /**
     * Prüft bei jedem ReadSlow()-Takt, ob der wiederkehrende automatische
     * Lastgang-Nachtrag fällig ist (Dietmars Wunsch 27.08.2026: bisher war
     * BackfillInexogyArchive() ausschließlich über den Formular-Knopf
     * erreichbar — die laufende Live-Abfrage (power_total/energy_*, siehe
     * ReadFast()/ReadSlow()) lief zwar bereits automatisch, der
     * Archiv-Nachtrag aber nie von selbst). Kein eigener Timer/Event nötig:
     * die ohnehin laufende SlowTimer (Default 60s) reicht, um ein
     * „mindestens N Minuten seit dem letzten Lauf"-Intervall zu prüfen.
     *
     * Zweimal überarbeitet, beide Male auf Dietmars Rückfrage hin:
     * (1) "einmal täglich ab HH:MM" → wiederkehrend alle N Minuten, weil
     * Inexogys /readings ohnehin nur 15-Minuten-Werte liefert. (2) fester
     * Tage-Rückblick bei jedem Takt → siehe DoAutoBackfillInexogyArchive():
     * "erst nachsehen, was schon da ist, dann nur das Fehlende holen" statt
     * statisch N Tage jedes Mal neu abzufragen.
     */
    private function MaybeAutoBackfillInexogy(): void
    {
        if (!in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true)) {
            return;
        }
        if (!$this->ReadPropertyBoolean('InexogyAutoBackfillEnabled')) {
            return;
        }
        $intervalSec = max(15, $this->ReadPropertyInteger('InexogyAutoBackfillIntervalMin')) * 60;
        $lastRun     = $this->ReadAttributeInteger('InexogyAutoBackfillLastRunTs');
        if ($lastRun > 0 && (time() - $lastRun) < $intervalSec) {
            return;
        }
        // Vor dem eigentlichen Nachtrag merken, nicht danach — ein
        // Absturz/Timeout mitten im Nachtrag soll nicht zu einer
        // Endlosschleife aus Sofort-Wiederholungsversuchen führen, sondern
        // erst wieder nach dem nächsten vollen Intervall.
        $this->WriteAttributeInteger('InexogyAutoBackfillLastRunTs', time());
        $m = $this->DoAutoBackfillInexogyArchive();
        $this->UpdateInexogyArchiveStatusField();
        // trigger_error im Timer-Kontext protokolliert der TimerPool als
        // FEHLER (❌) — beim viertelstündlichen Normalfall ("0 neue Werte"/
        // "bereits aktuell") stand dadurch alle 15 Minuten ein rotes
        // Nicht-Problem in Dietmars Log (live gesehen 30.08.2026). Deshalb:
        // echte Fehler (❌-Meldungen) weiter laut, der Normalfall nur noch
        // ins Instanz-Debug.
        if (str_starts_with($m, '❌')) {
            trigger_error('AutoBackfillInexogyArchive #' . $this->InstanceID . ': ' . $m, E_USER_NOTICE);
        } else {
            $this->SendDebug('AutoBackfill', $m, 0);
        }
    }

    /**
     * Ermittelt den bereits archivierten Stand je Zielvariable und holt nur
     * das seither Fehlende, statt bei jedem Takt einen festen Zeitraum neu
     * abzufragen (Dietmars Rückfrage 27.08.2026: "wie wäre es nachzusehen,
     * welche Daten schon da sind, und nur das Notwendige zu holen?").
     * `AC_GetLoggedValues(..., 0, $now, 1)` kostet dafür nur EINEN
     * Datensatz je Variable (die Ausgabe ist laut Doku absteigend sortiert,
     * Limit=1 liefert also exakt den neuesten) — kein Scan der ganzen
     * Historie. Das Minimum über alle Zielvariablen entscheidet (falls eine
     * hinterherhinkt, holt der Lauf für alle gemeinsam nach). Ein kleiner
     * Sicherheitsabstand (30 Minuten, zwei Inexogy-Intervalle) fängt einen
     * knapp verpassten oder nachträglich korrigierten Wert ab — der
     * bestehende Dopplungsschutz in DoBackfillInexogyArchive() macht
     * überlappendes Nachfragen ohnehin gefahrlos.
     *
     * `InexogyAutoBackfillDays` ist damit keine feste Fenstergröße mehr,
     * sondern eine Obergrenze: greift nur, wenn noch nie archiviert wurde
     * oder eine größere Lücke besteht (z. B. nach einem längeren Ausfall) —
     * verhindert, dass eine sehr alte/fehlende Archivmarke einen
     * unbeabsichtigt riesigen Nachtrag auslöst.
     *
     * Bereichsermittlung bewusst in ComputeAutoBackfillRange() ausgelagert,
     * getrennt vom eigentlichen Netzwerkzugriff — lässt sich dadurch isoliert
     * prüfen (.tools/test-auto-backfill.php), ohne einen echten
     * API-Aufruf zu benötigen.
     */
    private function DoAutoBackfillInexogyArchive(): string
    {
        [$from, $to, $error] = $this->ComputeAutoBackfillRange();
        if ($error !== null) {
            return $error;
        }
        return $this->DoBackfillInexogyArchive($from, $to);
    }

    /**
     * Liefert `[$from, $to, $error]` — bei Erfolg `$error === null` und
     * `[$from, $to]` der tatsächlich fehlende Zeitraum; sonst `$error` als
     * anzuzeigender Text und `$from`/`$to` bedeutungslos (0).
     */
    private function ComputeAutoBackfillRange(): array
    {
        [$watermark, $error] = $this->InexogyArchiveWatermark();
        if ($error !== null) {
            return [0, 0, $error];
        }
        $now     = time();
        $capDays = max(1, $this->ReadPropertyInteger('InexogyAutoBackfillDays'));
        $capFrom = $now - $capDays * 86400;
        $from    = max($capFrom, $watermark - 1800);
        if ($from >= $now) {
            return [0, 0, MHUB_Text::t('ℹ️ Archive is already up to date.')];
        }
        return [$from, $now, null];
    }

    /**
     * Liefert `[$watermarkTs, $error]` — der neueste Zeitstempel, der für
     * ALLE DREI Zielvariablen (`energy_import`/`energy_export`/
     * `power_total`) bereits im Archiv steht (Minimum über die drei, s.
     * `ComputeAutoBackfillRange()`), oder `[0, $errorText]` bei fehlendem
     * Archiv-Modul/fehlenden Zielvariablen. Dietmars Wunsch (27.08.2026):
     * „Zeitstempel des letzten vorhandenen Datensatzes anzeigen" — im
     * eigenen Formular (`InexogyArchiveStatus`-Label) UND über
     * `GetFunctions()` als `archiveWatermarkTs` für Konsumenten wie das
     * Dashboard, dessen Strompreis-Diagramm auf denselben archivierten
     * Energiezählern aufbaut und deshalb genau diese Verzögerung erbt.
     * `AC_GetLoggedValues(..., 0, $now, 1)` kostet je Variable nur EINEN
     * Datensatz (absteigend sortiert laut SDK-Doku, Limit=1 = der
     * neueste) — kein Scan der Historie.
     */
    private function InexogyArchiveWatermark(): array
    {
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) === 0) {
            return [0, MHUB_Text::t('❌ No archive module (Archive Control) found on this installation.')];
        }
        $archiveID = $archiveIDs[0];
        $now       = time();

        $watermark = null;
        foreach (['energy_import', 'energy_export', 'power_total'] as $ident) {
            $vid = $this->FindVarByIdent($ident);
            if (!$vid) {
                continue;
            }
            $latest = AC_GetLoggedValues($archiveID, $vid, 0, $now, 1);
            $ts = (int) ($latest[0]['TimeStamp'] ?? 0);
            if ($watermark === null || $ts < $watermark) {
                $watermark = $ts;
            }
        }
        if ($watermark === null) {
            return [0, MHUB_Text::t('❌ No target variable (energy_import/energy_export/power_total) present.')];
        }
        return [$watermark, null];
    }

    /**
     * Formular-Anzeige des Archiv-Wasserstands (siehe `InexogyArchiveWatermark()`).
     * `visible=false`/leer, solange nichts archiviert ist — kein Text, der
     * einen Stand vorgaukelt, den es noch nicht gibt.
     */
    private function UpdateInexogyArchiveStatusField(): void
    {
        if (!in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true)) {
            return;
        }
        [$watermark, $error] = $this->InexogyArchiveWatermark();
        if ($error !== null || $watermark === 0) {
            $this->UpdateFormField('InexogyArchiveStatus', 'visible', false);
            return;
        }
        $ageMin = (int) round((time() - $watermark) / 60);
        $this->UpdateFormField(
            'InexogyArchiveStatus',
            'caption',
            MHUB_Text::t('📊 Archive load profile complete up to %s (%d min ago).', date('d.m.Y H:i', $watermark), $ageMin)
        );
        $this->UpdateFormField('InexogyArchiveStatus', 'visible', true);
    }

    /**
     * Gemeinsamer Kern für den manuellen Knopf (BackfillInexogyArchive())
     * und den automatischen wiederkehrenden Lauf
     * (DoAutoBackfillInexogyArchive()) — bewusst NICHT als zusätzlicher
     * Parameter an BackfillInexogyArchive() selbst, das ist eine bereits
     * veröffentlichte MHUB_-Funktion (siehe CLAUDE.md, "PREFIX_-Funktionen
     * sind fixer Arität" — ein zusätzlicher Parameter würde dort den
     * bestehenden Formular-Knopf brechen, der sie einarmig aufruft). Nimmt
     * bewusst einen exakten Zeitstempel statt einer Tage-Anzahl entgegen —
     * der automatische Pfad braucht Minuten-Genauigkeit (siehe
     * DoAutoBackfillInexogyArchive()), eine Tage-Rundung hätte dessen
     * ganzen Vorteil zunichtegemacht.
     */
    private function DoBackfillInexogyArchive(int $fromOverall, int $toOverall): string
    {
        if (!in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true)) {
            return MHUB_Text::t('❌ No Inexogy instance.');
        }
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) === 0) {
            return MHUB_Text::t('❌ No archive module (Archive Control) found on this installation.');
        }
        $archiveID = $archiveIDs[0];

        // Feld → [Ziel-Ident, Rohwert-Skalierung] — dieselben Skalen wie
        // MHUB_InexogyDriver::readFast()/readSlow(), damit live abgefragte
        // und nachgetragene Werte konsistent bleiben.
        $fields = [
            'energy'    => ['ident' => 'energy_import', 'scale' => 1e10],
            'energyOut' => ['ident' => 'energy_export', 'scale' => 1e10],
            'power'     => ['ident' => 'power_total',   'scale' => 1000.0],
        ];
        $vids = [];
        foreach ($fields as $field => $t) {
            $vid = $this->FindVarByIdent($t['ident']);
            if ($vid) {
                $vids[$field] = $vid;
            }
        }
        if (count($vids) === 0) {
            return MHUB_Text::t('❌ No target variable (energy_import/energy_export/power_total) present.');
        }
        // Nur in Variablen nachtragen, die der Benutzer archiviert (Store-Checkliste 19: MeterHub schaltet die Archivierung
        // nie von selbst ein) — sonst gäbe es nichts, wohin der Nachtrag schreiben könnte.
        $notLogged = [];
        foreach ($vids as $field => $vid) {
            if (!@AC_GetLoggingStatus($archiveID, $vid)) {
                $notLogged[] = IPS_GetName($vid);
                unset($vids[$field]);
            }
        }
        if (count($vids) === 0) {
            return MHUB_Text::t('❌ The target variables are not archived yet. Please click "Enable archiving for these variables" at the top of the form and then start the backfill again.');
        }

        $chunkDays  = self::INEXOGY_BACKFILL_CHUNK_DAYS;
        $meterId    = $this->ReadPropertyString('InexogyMeterID');
        $c          = $this->GetTransport();

        $totals   = array_fill_keys(array_keys($vids), ['new' => 0, 'existing' => 0]);
        $emptyChunks = [];
        // Letzter gültiger Inexogy-Zählerstand (nur Inexogys eigene Reihe,
        // nicht das Archiv — dort liegen auch Live-Werte, die minimal vom
        // offiziellen Viertelstundenwert abweichen).
        $lastOfficial = ['energy' => null, 'energyOut' => null];
        $rejected = 0;
        $chunkFrom = $fromOverall;
        while ($chunkFrom < $toOverall) {
            $chunkTo  = min($chunkFrom + $chunkDays * 86400, $toOverall);
            $readings = $c->getReadings($meterId, $chunkFrom * 1000, $chunkTo * 1000, 'fifteen_minutes');
            if (count($readings) === 0) {
                $rejectedAuth = $c->getLastHttpCode() === 401;
                if ($rejectedAuth) {
                    $this->NoteInexogyAuth(true);
                }
                $emptyChunks[] = date('d.m.Y', $chunkFrom) . '–' . date('d.m.Y', $chunkTo) . ' (' . $c->getLastError() . ($rejectedAuth ? MHUB_Text::t(' — access token rejected, please log in again under "Cloud access"') : '') . ')';
                $chunkFrom = $chunkTo;
                continue;
            }
            usort($readings, fn($a, $b) => ($a['time'] ?? 0) <=> ($b['time'] ?? 0));
            // Zählerschutz (0.26.6, Live-Befund 10./11.09.2026, auch in
            // Inexogys eigenem CSV-Export bestätigt): für eine Lücke des Smart-
            // Meter-Gateways liefert Inexogy Zählerstand 0 statt „kein Wert"
            // und rechnet daraus ±24 MW Leistung. Ein Datensatz gilt nur, wenn
            // beide Zählerstände > 0 sind und nicht hinter den letzten gültigen
            // Stand zurückfallen. Die Leistung wird für ungültige Datensätze UND
            // den ersten gültigen danach verworfen (dort steht der Rücksprung).
            $valid = [];
            foreach ($readings as $i => $r) {
                $ok = true;
                foreach (['energy', 'energyOut'] as $ef) {
                    $raw = $r['values'][$ef] ?? null;
                    if ($raw === null) {
                        continue;
                    }
                    $v = (float)$raw / 1e10;
                    if ($v <= 0 || ($lastOfficial[$ef] !== null && $v < $lastOfficial[$ef])) {
                        $ok = false;
                    }
                }
                if ($ok) {
                    foreach (['energy', 'energyOut'] as $ef) {
                        if (($r['values'][$ef] ?? null) !== null) {
                            $lastOfficial[$ef] = (float)$r['values'][$ef] / 1e10;
                        }
                    }
                } else {
                    $rejected++;
                }
                $valid[$i] = $ok;
            }
            foreach ($vids as $field => $vid) {
                // Schon archivierte Zeitpunkte je Block auslassen statt
                // blind erneut einzutragen — ob AC_AddLoggedValues() bei
                // einer Kollision überschreibt oder dupliziert, ist nicht
                // dokumentiert; bei Abrechnungsdaten darf nichts doppelt
                // gezählt werden. Macht wiederholte/überlappende Läufe
                // (z. B. wöchentlich erneut mit größerem Rückstand) sicher.
                $existing = [];
                foreach (AC_GetLoggedValues($archiveID, $vid, $chunkFrom, $chunkTo, 0) as $e) {
                    $existing[$e['TimeStamp']] = true;
                }
                $datasets = [];
                foreach ($readings as $i => $r) {
                    $raw = $r['values'][$field] ?? null;
                    if ($raw === null) {
                        continue;
                    }
                    if (!$valid[$i] || ($field === 'power' && $i > 0 && !$valid[$i - 1])) {
                        continue;
                    }
                    $ts = (int)(($r['time'] ?? 0) / 1000);
                    if (isset($existing[$ts])) {
                        $totals[$field]['existing']++;
                        continue;
                    }
                    $datasets[] = ['TimeStamp' => $ts, 'Value' => $raw / $fields[$field]['scale']];
                }
                if (count($datasets) > 0 && AC_AddLoggedValues($archiveID, $vid, $datasets)) {
                    $totals[$field]['new'] += count($datasets);
                }
            }
            $chunkFrom = $chunkTo;
        }

        // Live-Zwischenwerte, die jetzt von offiziellen Viertelstundenwerten
        // abgedeckt sind, entfernen (0.26.9) — sonst liegen zwei Quellen mit
        // leicht verschiedenen Ständen im selben Zähler-Archiv, jeder
        // Rückschritt wird von Symcons Zähler-Verdichtung doppelt gezählt
        // (Befund 12.09.2026: +1,5 kWh Bezug am 10.09., rund 60 %).
        $cleaned = 0;
        foreach (['energy', 'energyOut'] as $ef) {
            if (isset($vids[$ef])) {
                $cleaned += $this->CleanStaleLivePoints($archiveID, $vids[$ef], $fromOverall - 1800, time(), true)[0];
            }
        }

        // Aggregation erst einmal am Ende neu bilden, nicht je Block —
        // AC_ReAggregateVariable() bezieht sich ohnehin auf die ganze
        // Variable, wiederholtes Aufrufen je Block wäre nur unnötige Last.
        foreach ($vids as $field => $vid) {
            if ($totals[$field]['new'] > 0) {
                $this->ReAggregate($archiveID, $vid);
            }
        }

        $spanH = round(($toOverall - $fromOverall) / 3600, 1);
        $range = $spanH < 48
            ? date('d.m.Y H:i', $fromOverall) . ' – ' . date('d.m.Y H:i', $toOverall) . " ({$spanH} h)"
            : date('d.m.Y', $fromOverall) . ' – ' . date('d.m.Y', $toOverall);
        $out = [MHUB_Text::t('Period: %s, processed in blocks of %d days', $range, $chunkDays)];
        if ($cleaned > 0) {
            $out[] = MHUB_Text::t('🧹 %d live intermediate value(s) removed that are now covered by official quarter-hour values.', $cleaned);
        }
        if ($rejected > 0) {
            $out[] = MHUB_Text::t('⚠️ %d record(s) from Inexogy discarded (meter reading 0 or backwards — Inexogy fills transmission gaps with 0). The gap stays open; "Check / repair energy archive" can fill it.', $rejected);
        }
        foreach ($fields as $field => $t) {
            if (!isset($vids[$field])) {
                $out[] = MHUB_Text::t('%s: variable not present, skipped.', $t['ident']);
                continue;
            }
            $out[] = MHUB_Text::t('%s (#%d): %d new values backfilled (%d already present, skipped)', $t['ident'], $vids[$field], $totals[$field]['new'], $totals[$field]['existing']);
        }
        if ($emptyChunks) {
            $out[] = MHUB_Text::t('Without data from Inexogy: %s', implode('; ', $emptyChunks));
        }
        return implode("\n", $out);
    }

    // Öffentlicher Wrapper, damit Treiber prüfen können, ob eine optionale
    // Gruppe aktiv ist (ReadPropertyBoolean ist protected).
    public function GroupActive(string $propName): bool
    {
        return $this->ReadPropertyBoolean($propName);
    }

    // -----------------------------------------------------------------------
    // Funktionszuordnung
    // -----------------------------------------------------------------------

    private function IsSinglePhaseMeter(): bool
    {
        return in_array($this->ReadPropertyString('Meter'), self::SINGLE_PHASE_METERS, true);
    }

    // Einphasige Zähler kennen nur „ganzes Gerät" — ein früher gespeicherter „perphase" zählt dort nicht.
    private function IsPerPhaseMode(): bool
    {
        return !$this->IsSinglePhaseMeter() && $this->ReadPropertyString('MeasureMode') === 'perphase';
    }

    /**
     * Aktive Zuordnungen als Liste.
     * [ ['slot'=>'total|L1|L2|L3', 'key'=>'heatpump', 'label'=>'Wärmepumpe',
     *    'icon'=>'Temperature', 'power'=>ident, 'import'=>ident, 'export'=>ident] ]
     */
    private function FunctionAssignments(): array
    {
        $out = [];
        $mk = function (string $slot, string $key, string $custom, string $p, string $i, string $e) use (&$out) {
            if ($key === '' || $key === 'none' || !isset(self::FUNCTIONS[$key])) {
                return;
            }
            [$name, $icon] = self::FUNCTIONS[$key];
            $name = MHUB_Text::t($name);   // Funktionsname aus der Konstante, übersetzt an der Verwendung
            $custom = trim($custom);
            if ($custom !== '') {
                $label = $custom;
            } else {
                // Ohne eigenes Label greift der Instanzname, wenn der Nutzer ihn
                // gesetzt hat — sonst bleiben mehrere gleichfunktionale Zähler (z. B.
                // 24 einzelne WR mit Funktion 'pv') alle gleich „PV-Erzeugung“
                // beschriftet und sind auf einer Kachel nicht zu unterscheiden
                // (Dashboard-Fund am Solarpark, 14.09.2026). Rückfall auf den
                // pauschalen Funktionsnamen bleibt, solange die Instanz ihren
                // Symcon-Standardnamen trägt — der ist kein aussagekräftiger Name.
                $instName = trim(IPS_GetName($this->InstanceID));
                $label = ($instName !== '' && $instName !== self::DEFAULT_INSTANCE_NAME) ? $instName : $name;
            }
            $out[] = [
                'slot'   => $slot,
                'key'    => $key,
                'label'  => $label,
                'icon'   => $icon,
                'power'  => $p,
                'import' => $i,
                'export' => $e,
            ];
        };

        if ($this->IsPerPhaseMode()) {
            $n = 1;
            foreach (['L1', 'L2', 'L3'] as $ph) {
                $mk($ph,
                    $this->ReadPropertyString('Func' . $ph),
                    $this->ReadPropertyString('FuncLabel' . $ph),
                    'p_l' . $n, 'energy_import_l' . $n, 'energy_export_l' . $n);
                $n++;
            }
        } else {
            $mk('total',
                $this->ReadPropertyString('FuncTotal'),
                $this->ReadPropertyString('FuncTotalLabel'),
                'power_total', 'energy_import', 'energy_export');
        }
        return $out;
    }

    // Ident -> Zuordnung, für Benennung/Icon der Quellvariablen.
    private function FunctionByIdent(): array
    {
        $map = [];
        foreach ($this->FunctionAssignments() as $a) {
            foreach (['power', 'import', 'export'] as $role) {
                if ($a[$role] !== '') {
                    $map[$a[$role]] = $a;
                }
            }
        }
        return $map;
    }

    // Idents der optionalen Sammel-Variablen (nur wenn aktiviert).
    private function MirrorDefs(): array
    {
        if (!$this->ReadPropertyBoolean('FuncMirrors')) {
            return [];
        }
        $defs = [];
        foreach ($this->FunctionAssignments() as $a) {
            $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($a['key']));
            $defs[] = ['fn_' . $slug . '_power',  MHUB_Text::t('%s — Power', $a['label']),       'F', 'NRG.Watt',   true, 'function', $a['icon'], $a['power']];
            $defs[] = ['fn_' . $slug . '_import', MHUB_Text::t('%s — Import', $a['label']),          'F', 'NRG.kWh', true, 'function', $a['icon'], $a['import']];
            $defs[] = ['fn_' . $slug . '_export', MHUB_Text::t('%s — Feed-in', $a['label']),    'F', 'NRG.kWh', true, 'function', $a['icon'], $a['export']];
        }
        return $defs;
    }

    // Spiegelt die zugeordneten Kanäle in die Sammel-Variablen.
    private function UpdateMirrors()
    {
        foreach ($this->MirrorDefs() as $d) {
            $src = $this->FindVarByIdent($d[7]);
            $dst = $this->FindVarByIdent($d[0]);
            if ($src && $dst) {
                SetValueFloat($dst, (float)GetValue($src));
            }
        }
    }

    // Zähler, deren Werte NICHT lokal-echtzeitnah, sondern über eine Cloud-API
    // mit Sekunden-Latenz kommen. Bestimmt das Vertragsfeld `latency` und den
    // Transport (MHUB_InexogyClient statt MHUB_ModbusTcpClient). Modbus-Zähler sind alle
    // 'realtime'.
    private const CLOUD_METERS = ['inexogy'];
    // Zählertypen mit Sollwert-Schreibzugriff (blue'Log RPC/Power Control) —
    // steuert Formular-Sichtbarkeit des Sollwert-Panels (GetConfigurationForm()/
    // OnChangeMeter()). Ob der TREIBER tatsächlich schreibt, prüft der Code
    // separat per `instanceof MHUB_WritableMeterDriverInterface` — diese Liste
    // ist nur für die UI-Sichtbarkeit vor dem ersten "Übernehmen" nötig.
    private const WRITABLE_METERS = ['bluelog_rpc', 'bluelog_powercontrol'];
    // Nur bei diesem einen Zählertyp existiert das Gültigkeitszeit-/Watchdog-
    // Register (5006/5008) laut Herstellerdoku — Power Control hat keins.
    private const RPC_VALID_TIME_METERS = ['bluelog_rpc'];

    /**
     * Öffentliche Abfrage der Zuordnung für andere Module (EMS, Kacheln):
     * liefert JSON mit Modus und je Zuordnung Funktion, Bezeichnung und den
     * Variablen-IDs für Leistung, Bezug und Einspeisung.
     *
     * Vertragserweiterung (abgestimmt 22.07.2026): `latency` und `authority`
     * sind ZWEI orthogonale Achsen — „darf das EMS darauf regeln?" (realtime/
     * delayed) und „steht der Wert auf der Rechnung?" (billing/auxiliary). Ein
     * Konsument mit zwei grid-Zählern am selben Anschluss wählt damit den
     * richtigen für den richtigen Zweck. `energyKind` je Zuordnung trennt
     * kumulative Zählerstände (Differenzen bilden) von Periodenwerten
     * (summieren). Alle Felder additiv; alte Konsumenten ignorieren sie.
     */
    // -----------------------------------------------------------------------
    // Zählerschutz und Energie-Archiv prüfen/reparieren (0.26.6, Dietmars
    // Auftrag 12.09.2026). Anlass: Inexogy lieferte für 10.09. 22:45 bis
    // 11.09. 10:45 Zählerstand 0 (auch in Inexogys eigenem Portal/CSV), im
    // Archiv entstanden ±10 993 kWh je Viertelstunde und ±24 MW.
    // -----------------------------------------------------------------------

    /** So viele Rückschritte in Folge, dann gilt der niedrigere Stand als Zählertausch. */
    private const COUNTER_RESET_CONFIRM = 30;

    /**
     * Ein Schritt des Zählerschutzes, frei von Symcon-Aufrufen (Prüfstand).
     * Rückgabe [übernehmen?, neue Anzahl Rückschritte in Folge, Grund].
     * Ein kumulativer Zählerstand ist nie 0 und läuft nie rückwärts — außer
     * bei einem Zählertausch: der wird angenommen, wenn der niedrigere Stand
     * COUNTER_RESET_CONFIRM-mal in Folge kommt (ein einzelner Ausreißer nie).
     */
    private static function CounterGuardStep(float $current, float $new, int $rejects): array
    {
        if (!is_finite($new) || $new <= 0) {
            return [false, $rejects, 'null'];
        }
        if ($current <= 0 || $new >= $current) {
            return [true, 0, ''];
        }
        $rejects++;
        if ($rejects >= self::COUNTER_RESET_CONFIRM) {
            return [true, 0, 'reset'];
        }
        return [false, $rejects, 'rueckwaerts'];
    }

    /** Zählerschutz für einen Live-Wert; true = schreiben. */
    private function AcceptCounter(int $vid, float $new): bool
    {
        $state = json_decode((string)$this->ReadAttributeString('CounterGuard'), true);
        $state = is_array($state) ? $state : [];
        $before = (int)($state[$vid] ?? 0);
        $current = (float)GetValue($vid);
        [$ok, $rejects, $why] = self::CounterGuardStep($current, $new, $before);
        if ($rejects !== $before) {
            $state[$vid] = $rejects;
            $this->WriteAttributeString('CounterGuard', (string)json_encode($state));
        }
        if ($why === 'reset') {
            $this->LogMessage(MHUB_Text::t('%s: meter reading permanently lower (%s → %s) — accepted as a meter replacement.', IPS_GetName($vid), $current, $new), KL_WARNING);
        } elseif (!$ok) {
            $this->SendDebug('Zählerschutz', IPS_GetName($vid) . ': ' . ($why === 'null' ? 'Wert 0/ungültig' : 'rückwärts') . " verworfen ($new, Stand $current)", 0);
        }
        return $ok;
    }

    /** Ungültige Punkte einer Zählerreihe (aufsteigend [[ts, v], …]) zu Lücken zusammenfassen. */
    private static function FindCounterGaps(array $pts): array
    {
        $spans = [];
        $last = null;
        $cur = null;
        foreach ($pts as [$ts, $v]) {
            $bad = !is_finite($v) || $v <= 0 || ($last !== null && $v < $last[1] - 1e-9);
            if ($bad) {
                if ($cur === null) {
                    $cur = ['before' => $last, 'bad' => [], 'zero' => false, 'after' => null];
                }
                $cur['bad'][] = $ts;
                if (!is_finite($v) || $v <= 0) {
                    $cur['zero'] = true;
                }
                continue;
            }
            if ($cur !== null) {
                $cur['after'] = [$ts, $v];
                $spans[] = $cur;
                $cur = null;
            }
            $last = [$ts, $v];
        }
        if ($cur !== null) {
            $spans[] = $cur;
        }
        return $spans;
    }

    /** Linear interpolierter Wert einer aufsteigenden Reihe [[ts, v], …] bei $t, null außerhalb. */
    private static function InterpAt(array $pts, int $t): ?float
    {
        $prev = null;
        foreach ($pts as [$ts, $v]) {
            if ($ts === $t) {
                return (float)$v;
            }
            if ($ts > $t) {
                if ($prev === null) {
                    return null;
                }
                return $prev[1] + ($v - $prev[1]) * ($t - $prev[0]) / max(1, $ts - $prev[0]);
            }
            $prev = [$ts, (float)$v];
        }
        return null;
    }

    /**
     * Werte für die Zeitpunkte einer Lücke: bekannte Gesamtmenge
     * (after − before) verteilt nach dem Verlauf des Referenzzählers, ohne
     * Referenz gleichmäßig. Immer monoton, nie über den Endwert hinaus.
     */
    private static function ShapeFill(array $before, array $after, array $tsList, ?array $refPts): array
    {
        [$ta, $va] = $before;
        [$tb, $vb] = $after;
        $d = $vb - $va;
        $ra = $refPts ? self::InterpAt($refPts, $ta) : null;
        $rb = $refPts ? self::InterpAt($refPts, $tb) : null;
        $useRef = $ra !== null && $rb !== null && ($rb - $ra) > 1e-9;
        $out = [];
        $prev = $va;
        foreach ($tsList as $ts) {
            $f = ($ts - $ta) / max(1, $tb - $ta);
            if ($useRef) {
                $r = self::InterpAt($refPts, $ts);
                if ($r !== null) {
                    $f = ($r - $ra) / ($rb - $ra);
                }
            }
            $f = max(0.0, min(1.0, $f));
            $v = max($prev, $va + $d * $f);
            $out[] = [$ts, $v];
            $prev = $v;
        }
        return $out;
    }

    /** Archivierte Punkte tageweise lesen (Grenze 50 000 Zeilen je Abfrage), aufsteigend. */
    private function LoadArchivePoints(int $ac, int $vid, int $from, int $to): array
    {
        $pts = [];
        for ($t = $from; $t < $to; $t += 86400) {
            foreach (array_reverse(AC_GetLoggedValues($ac, $vid, $t, min($to, $t + 86399), 0)) as $r) {
                $pts[] = [(int)$r['TimeStamp'], (float)$r['Value']];
            }
        }
        return $pts;
    }

    /**
     * Welcher Zähler der Referenz passt zu dieser Reihe? Nicht über den Namen
     * (am PAC2200 in Dietmars Anlage waren Bezug/Abgabe vertauscht), sondern
     * über die Übereinstimmung der Zunahme in den 24 h vor der Lücke.
     * Rückgabe [Variablen-ID, Verhältnis] oder [0, 0].
     */
    private function PickReference(int $ac, array $candidates, array $targetPts, int $t): array
    {
        $valid = array_values(array_filter($targetPts, fn($p) => $p[0] <= $t && $p[1] > 0));
        $t0 = $t - 86400;
        $a = self::InterpAt($valid, $t0) ?? ($valid[0][1] ?? null);
        $tStart = self::InterpAt($valid, $t0) !== null ? $t0 : ($valid[0][0] ?? $t);
        $b = self::InterpAt($valid, $t);
        if ($a === null || $b === null || $b - $a < 0.01) {
            return [0, 0.0];
        }
        $best = [0, 0.0];
        $bestDist = INF;
        foreach ($candidates as $vid) {
            $rp = $this->LoadArchivePoints($ac, $vid, $tStart - 3600, $t + 3600);
            $ra = self::InterpAt($rp, $tStart);
            $rb = self::InterpAt($rp, $t);
            if ($ra === null || $rb === null || $rb - $ra <= 0) {
                continue;
            }
            $ratio = ($rb - $ra) / ($b - $a);
            $dist = abs(log($ratio));
            if ($ratio >= 0.5 && $ratio <= 2.0 && $dist < $bestDist) {
                $best = [$vid, $ratio];
                $bestDist = $dist;
            }
        }
        return $best;
    }

    public function CheckEnergyArchive(int $ReferenceID, int $Days, bool $WithBackward): string
    {
        return $this->EnergyArchiveRepair($ReferenceID, $Days, false, $WithBackward);
    }

    public function RepairEnergyArchive(int $ReferenceID, int $Days, bool $WithBackward): string
    {
        return $this->EnergyArchiveRepair($ReferenceID, $Days, true, $WithBackward);
    }

    /**
     * Energiezähler im Archiv auf Nullwerte und Rückschritte prüfen und — nur
     * mit $apply — reparieren: ungültige Punkte löschen, die bekannte Menge
     * zwischen dem letzten gültigen Stand davor und dem ersten danach nach dem
     * Referenzzähler (sonst gleichmäßig) verteilen, die Leistung in
     * Nullwert-Lücken aus der aufgefüllten Energie neu berechnen, neu
     * verdichten. Nicht angefasst: Lücken ohne gültigen Stand danach (evtl.
     * Zählertausch) und längere Rückschritt-Folgen ohne Nullwerte (unklar,
     * welcher Wert der Ausreißer ist) — beide nur gemeldet.
     */
    private function EnergyArchiveRepair(int $refId, int $days, bool $apply, bool $withBackward = true): string
    {
        $acs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($acs) === 0) {
            return MHUB_Text::t('❌ No archive module (Archive Control) found.');
        }
        $ac = $acs[0];
        // Höchstens 45 Tage: bei Minutenwerten ~65 000 Punkte je Reihe, sicher
        // unter dem 32-MB-Limit der Skript-Engine.
        $days = max(1, min(45, $days));
        $to = time();
        $from = $to - $days * 86400;
        $energy = [];
        foreach (['energy_import' => MHUB_Text::t('Import'), 'energy_export' => MHUB_Text::t('Feed-in')] as $ident => $label) {
            $vid = $this->FindVarByIdent($ident);
            if ($vid && AC_GetLoggingStatus($ac, $vid)) {
                $energy[$ident] = [$vid, $label];
            }
        }
        if (!$energy) {
            return MHUB_Text::t('ℹ️ No archived energy meters (import/feed-in) on this instance.');
        }
        $refCands = [];
        if ($refId > 0 && $refId !== $this->InstanceID && IPS_InstanceExists($refId)) {
            foreach (['energy_import', 'energy_export'] as $ident) {
                $rv = $this->FindIdentRecursive($refId, $ident);
                if ($rv && AC_GetLoggingStatus($ac, $rv)) {
                    $refCands[] = $rv;
                }
            }
        }
        $fmt = fn($t) => date('d.m.Y H:i', $t);
        $lines = [$apply ? MHUB_Text::t('✅ Energy archive repaired (%d days):', $days) : MHUB_Text::t('🔎 Dry run, nothing changed (%d days):', $days)];
        $plan = [];
        $repaired = [];
        $zeroSpans = [];
        foreach ($energy as $ident => [$vid, $label]) {
            $pts = $this->LoadArchivePoints($ac, $vid, $from, $to);
            foreach (AC_GetLoggedValues($ac, $vid, 0, $from - 1, 50) as $r) {
                if ((float)$r['Value'] > 0) {
                    array_unshift($pts, [(int)$r['TimeStamp'], (float)$r['Value']]);
                    break;
                }
            }
            $spans = self::FindCounterGaps($pts);
            $del = [];
            $add = [];
            $nZero = 0;
            $nBack = 0;
            $skipBack = 0;
            $notes = [];
            foreach ($spans as $s) {
                $n = count($s['bad']);
                $range = $fmt($s['bad'][0]) . ($n > 1 ? ' – ' . $fmt(end($s['bad'])) : '') . ' (' . ($n > 1 ? MHUB_Text::t('%d points', $n) : MHUB_Text::t('%d point', $n)) . ')';
                if ($s['zero']) {
                    $nZero += $n;
                } else {
                    $nBack += $n;
                }
                if ($s['before'] === null || $s['after'] === null) {
                    $notes[] = $s['before'] === null
                        ? MHUB_Text::t('   • %s: no valid reading before it — not repaired (possibly a meter replacement).', $range)
                        : MHUB_Text::t('   • %s: no valid reading after it — not repaired (possibly a meter replacement).', $range);
                    continue;
                }
                if (!$s['zero'] && !$withBackward) {
                    $skipBack += $n;
                    continue;
                }
                if (!$s['zero'] && $n > 3) {
                    $notes[] = MHUB_Text::t('   • %s: longer series of steps backwards without zero values — unclear which value is the outlier, not repaired.', $range);
                    continue;
                }
                $refPts = null;
                $how = MHUB_Text::t('evenly distributed');
                if ($s['zero'] && $refCands) {
                    [$rv, $ratio] = $this->PickReference($ac, $refCands, $pts, $s['before'][0]);
                    if ($rv) {
                        $refPts = $this->LoadArchivePoints($ac, $rv, $s['before'][0] - 3600, $s['after'][0] + 3600);
                        $how = MHUB_Text::t('distributed following #%d %s (agreement of the 24 h before: %d %%)', $rv, IPS_GetName($rv), round($ratio * 100));
                    } else {
                        $how = MHUB_Text::t('evenly distributed (reference does not fit clearly)');
                    }
                }
                foreach (self::ShapeFill($s['before'], $s['after'], $s['bad'], $refPts) as $p) {
                    $add[] = $p;
                }
                foreach ($s['bad'] as $ts) {
                    $del[] = $ts;
                }
                if ($s['zero']) {
                    $notes[] = MHUB_Text::t('   • %s: %s kWh, %s.', $range, number_format($s['after'][1] - $s['before'][1], 3, ',', '.'), $how);
                    if ($ident === 'energy_import') {
                        $zeroSpans[] = [$s['before'][0], $s['after'][0], array_merge($s['bad'], [$s['after'][0]])];
                    }
                } elseif ($n <= 3 && count($notes) < 12) {
                    $notes[] = MHUB_Text::t('   • %s: step backwards, interpolated.', $range);
                }
            }
            $lines[] = MHUB_Text::t('%s (#%d): %d zero value(s), %d step(s) backwards', $label, $vid, $nZero, $nBack) . ($spans ? '' : MHUB_Text::t(' — all fine.'))
                . ($skipBack > 0 ? MHUB_Text::t(' — of which %d step(s) backwards deliberately left alone (switch "Also repair individual steps backwards" is off).', $skipBack) : '');
            $lines = array_merge($lines, $notes);
            $plan[$ident] = [$vid, $del, $add];
            // Reparierte Reihe nur rund um Nullwert-Lücken behalten (dafür
            // braucht sie die Leistungs-Neuberechnung). Die ganze Reihe
            // mehrfach im Speicher würde bei Minutenwerten über Wochen das
            // feste 32-MB-Limit der Skript-Engine sprengen.
            $windows = [];
            foreach ($spans as $s) {
                if ($s['zero'] && $s['before'] !== null && $s['after'] !== null) {
                    $windows[] = [$s['before'][0], $s['after'][0]];
                }
            }
            $inWin = function (int $ts) use ($windows): bool {
                foreach ($windows as [$wa, $wb]) {
                    if ($ts >= $wa && $ts <= $wb) {
                        return true;
                    }
                }
                return false;
            };
            $delSet = array_flip($del);
            $rep = [];
            foreach ($pts as $p) {
                if (!isset($delSet[$p[0]]) && $inWin($p[0])) {
                    $rep[] = $p;
                }
            }
            foreach ($add as $p) {
                if ($inWin($p[0])) {
                    $rep[] = $p;
                }
            }
            usort($rep, fn($a, $b) => $a[0] <=> $b[0]);
            $repaired[$ident] = $rep;
            unset($pts, $spans, $delSet);
        }

        // Leistung in Nullwert-Lücken aus der aufgefüllten Energie neu
        // berechnen (Bezug − Einspeisung je Abschnitt) — so gibt es kein
        // Vorzeichenproblem mit einer eventuell anders gepolten Referenz.
        $pvid = $this->FindVarByIdent('power_total');
        $powerPlan = [];
        if ($pvid && $zeroSpans && AC_GetLoggingStatus($ac, $pvid)) {
            $sign = $this->ReadPropertyBoolean('PowerInvert') ? -1.0 : 1.0;
            $toW = $this->ReadPropertyBoolean('EnergyUnitWh') ? 1.0 : 1000.0;
            foreach ($zeroSpans as [$ta, $tb, $tsList]) {
                $points = [];
                $prevT = $ta;
                foreach ($tsList as $t) {
                    $dI = (self::InterpAt($repaired['energy_import'], $t) ?? 0) - (self::InterpAt($repaired['energy_import'], $prevT) ?? 0);
                    $dE = isset($repaired['energy_export'])
                        ? (self::InterpAt($repaired['energy_export'], $t) ?? 0) - (self::InterpAt($repaired['energy_export'], $prevT) ?? 0)
                        : 0.0;
                    $h = max(1, $t - $prevT) / 3600.0;
                    $points[] = [$t, $sign * ($dI - $dE) / $h * $toW];
                    $prevT = $t;
                }
                $old = count(AC_GetLoggedValues($ac, $pvid, $ta + 1, $tb, 0));
                $powerPlan[] = [$ta, $tb, $points, $old];
            }
            $nOld = array_sum(array_column($powerPlan, 3));
            $nNew = array_sum(array_map(fn($p) => count($p[2]), $powerPlan));
            $lines[] = MHUB_Text::t('Power (#%d): %d value(s) in the zero-value gaps, replaced by %d calculated from the filled-in energy.', $pvid, $nOld, $nNew);
        }

        if (!$apply) {
            $total = array_sum(array_map(fn($p) => count($p[1]), $plan));
            $lines[] = $total > 0 ? MHUB_Text::t('→ "Repair" does exactly this.') : MHUB_Text::t('→ Nothing to repair.');
            return implode("\n", $lines);
        }
        foreach ($plan as $ident => [$vid, $del, $add]) {
            if (!$del) {
                continue;
            }
            foreach ($del as $ts) {
                AC_DeleteVariableData($ac, $vid, $ts, $ts);
            }
            usort($add, fn($a, $b) => $a[0] <=> $b[0]);
            if ($add) {
                AC_AddLoggedValues($ac, $vid, array_map(fn($p) => ['TimeStamp' => $p[0], 'Value' => $p[1]], $add));
            }
            $this->ReAggregate($ac, $vid);
        }
        if ($powerPlan) {
            foreach ($powerPlan as [$ta, $tb, $points]) {
                AC_DeleteVariableData($ac, $pvid, $ta + 1, $tb);
                AC_AddLoggedValues($ac, $pvid, array_map(fn($p) => ['TimeStamp' => $p[0], 'Value' => $p[1]], $points));
            }
            $this->ReAggregate($ac, $pvid);
        }
        $this->LogMessage(MHUB_Text::t('Energy archive repaired — %s', str_replace("\n", ' | ', implode("\n", $lines))), KL_MESSAGE);
        return implode("\n", $lines);
    }

    // -----------------------------------------------------------------------
    // Richtung (0.27.0, Dietmars Auftrag 12.09.2026). Anlass: An Dietmars
    // PAC2200 und an zwei Solarpark-Netzanschlüssen stand PowerInvert
    // zeitweise falsch. Seit 0.22.7 tauscht der Schalter die Energie-ZIELE —
    // jedes Umschalten ließ Bezug und Einspeisung aufeinander springen, die
    // gespeicherte Leistung behielt vor dem Umschalten ihr altes Vorzeichen.
    // Die Korrektur lief als Einzelskript; das muss jeder Nutzer ohne Hilfe
    // können:
    //   1. SyncInvertLayout(): beim Umschalten tauschen die Variablen ihre
    //      Idents — jede behält ihren Zähler und Verlauf, kein Sprung.
    //   2. DirectionArchive(): Altfälle im Archiv reparieren, Probelauf zuerst.
    //   3. GetDiagnostics(): stimmt die Richtung heute? Vergleich mit einer
    //      zweiten Quelle (Diagnose-Vertrag, Anzeige im Dashboard).
    // -----------------------------------------------------------------------

    /** Unter dieser Leistung (W) ist eine Richtung nicht sicher bestimmbar. */
    private const DIR_MIN_W = 100.0;
    /** Diagnose-Vertrag (1.1 = independent/referenceIDs, 1.2 = role_plausibility). Ein Zwischenspeicher mit anderer Version gilt als veraltet. */
    private const DIAG_CONTRACT = '1.2';
    /**
     * Rollen-Plausibilität (Dietmars Auftrag 14.09.2026): Anteil der Zeit mit
     * deutlich positiver/negativer Leistung, ab dem ein Unterzähler
     * (Rolle „Verbraucher"/„Erzeuger") auffällig bzw. kritisch wird — beide
     * Anteile müssen gleichzeitig erreicht sein, ein reiner Verbraucher/
     * Erzeuger darf durchaus die eine oder andere Richtung dominant zeigen.
     */
    private const ROLE_SHARE_AUFFAELLIG = 0.05;
    private const ROLE_SHARE_KRITISCH = 0.2;

    /**
     * Bezug/Abgabe-Paare dieser Instanz, [Bezug-Ident => [Bezug-ID, Abgabe-ID]]:
     * energy_import{Suffix} ↔ energy_export{Suffix} und die Sammel-Variablen
     * fn_*_import ↔ fn_*_export. Nur Paare mit beiden Seiten — genau die, die
     * EnergyIdentForInvert() tauscht.
     */
    private function EnergyPairs(): array
    {
        $byIdent = [];
        $collect = function (int $pid) use (&$collect, &$byIdent) {
            foreach (IPS_GetChildrenIDs($pid) as $cid) {
                $o = IPS_GetObject($cid);
                if ($o['ObjectType'] === 0) {
                    $collect($cid);
                } elseif ($o['ObjectType'] === 2 && $o['ObjectIdent'] !== '') {
                    $byIdent[$o['ObjectIdent']] = $cid;
                }
            }
        };
        $collect($this->InstanceID);
        $pairs = [];
        foreach ($byIdent as $ident => $vid) {
            $other = self::CounterpartIdent($ident);
            if ($other !== null && str_contains($ident, 'import') && isset($byIdent[$other])) {
                $pairs[$ident] = [$vid, $byIdent[$other]];
            }
        }
        ksort($pairs);
        return $pairs;
    }

    /** Gegen-Ident eines Bezug-/Abgabe-Idents, null wenn es keiner ist. */
    private static function CounterpartIdent(string $ident): ?string
    {
        if (preg_match('/^energy_import(_[a-z0-9]+)?$/', $ident)) {
            return str_replace('energy_import', 'energy_export', $ident);
        }
        if (preg_match('/^energy_export(_[a-z0-9]+)?$/', $ident)) {
            return str_replace('energy_export', 'energy_import', $ident);
        }
        if (preg_match('/^(fn_.+)_import$/', $ident, $m)) {
            return $m[1] . '_export';
        }
        if (preg_match('/^(fn_.+)_export$/', $ident, $m)) {
            return $m[1] . '_import';
        }
        return null;
    }

    /** Zwei Variablen tauschen Ident und Namen (Idents sind je Elternobjekt eindeutig, daher über einen Zwischen-Ident). */
    private function SwapIdentPair(int $a, int $b): void
    {
        $oa = IPS_GetObject($a);
        $ob = IPS_GetObject($b);
        IPS_SetIdent($a, 'mhub_tausch_' . $a);
        IPS_SetIdent($b, $oa['ObjectIdent']);
        IPS_SetIdent($a, $ob['ObjectIdent']);
        IPS_SetName($a, $ob['ObjectName']);
        IPS_SetName($b, $oa['ObjectName']);
    }

    /**
     * PowerInvert umgeschaltet? Dann tauschen die Energie-Variablen ihre
     * Rolle (Ident/Name). EnergyIdentForInvert() leitet das Zählerregister
     * ab jetzt ins andere Ziel — zusammen landet jedes Register weiter in
     * derselben Variable: kein Sprung, der Zählerschutz schlägt nicht an, und
     * der Verlauf jeder Variable bleibt eine durchgehende Zählerreihe. Die
     * Bezeichnung passt danach zur neuen Richtung. Zurückschalten macht es
     * rückgängig. Beim ersten Start einer Version mit dieser Regel (Attribut
     * leer) wird die bestehende Anordnung nur übernommen — alte Umschaltungen
     * repariert DirectionArchive().
     */
    private function SyncInvertLayout(): void
    {
        $want = $this->ReadPropertyBoolean('PowerInvert') ? 'on' : 'off';
        $had = $this->ReadAttributeString('InvertLayout');
        if ($had === $want) {
            return;
        }
        $this->WriteAttributeString('InvertLayout', $want);
        if ($had === '') {
            return;
        }
        $done = [];
        foreach ($this->EnergyPairs() as [$imp, $exp]) {
            $this->SwapIdentPair($imp, $exp);
            $done[] = '#' . $imp . ' ↔ #' . $exp;
        }
        if ($done) {
            $this->LogMessage($want === 'on'
                ? MHUB_Text::t('"Import/feed-in swapped" switched on — the energy meter variables have swapped their role (%s). Each keeps counting without a jump, the labels match the new direction. Please check scripts with hard-coded variable IDs.', implode(', ', $done))
                : MHUB_Text::t('"Import/feed-in swapped" switched off — the energy meter variables have swapped their role (%s). Each keeps counting without a jump, the labels match the new direction. Please check scripts with hard-coded variable IDs.', implode(', ', $done)), KL_MESSAGE);
        }
    }

    public function CheckDirectionArchive(int $Days): string
    {
        return $this->DirectionArchive($Days, false);
    }

    public function RepairDirectionArchive(int $Days): string
    {
        return $this->DirectionArchive($Days, true);
    }

    /**
     * Archiv in sich stimmig machen, Altfälle vor 0.27.0:
     * Energie — jede Variable eines Paars wird wieder eine durchgehende
     * Zählerreihe (RetrackStep erkennt die Kreuzsprünge). Reicht der
     * vertauschte Abschnitt bis heute, tauschen die Variablen zusätzlich ihre
     * Rolle, damit sie ohne Sprung weiterzählen.
     * Leistung — nur wenn die Energie schon stimmt: je Viertelstunde Richtung
     * aus Bezug − Einspeisung gegen das Vorzeichen der archivierten Leistung,
     * gegenläufige Abschnitte werden gedreht (Kanten per ChangePoint).
     * Ob die Richtung HEUTE stimmt, sagt GetDiagnostics() — nicht diese
     * Funktion. Speicherfest: alles tageweise.
     */
    private function DirectionArchive(int $days, bool $apply): string
    {
        $acs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($acs) === 0) {
            return MHUB_Text::t('❌ No archive module (Archive Control) found.');
        }
        $ac = $acs[0];
        $days = max(1, min(120, $days));
        // Die letzten 10 Minuten bleiben außen vor: frische Werte liegen evtl.
        // noch im Schreibpuffer des Archivs, dahinter lehnt AC_AddLoggedValues
        // jedes Nachtragen ab (live gesehen am Solarpark, 12.09.2026).
        $to = intdiv(time() - 600, 900) * 900;
        $from = $to - $days * 86400;
        $fmt = fn($t) => date('d.m.Y H:i', $t);
        $lines = [$apply ? MHUB_Text::t('✅ Direction in the archive corrected (%d days):', $days) : MHUB_Text::t('🔎 Dry run, nothing changed (%d days):', $days)];
        $energyMoved = 0;
        $todo = false;
        $changed = false;
        foreach ($this->EnergyPairs() as [$a, $b]) {
            if (!AC_GetLoggingStatus($ac, $a) || !AC_GetLoggingStatus($ac, $b)) {
                continue;
            }
            $head = IPS_GetName($a) . ' (#' . $a . ') / ' . IPS_GetName($b) . ' (#' . $b . ')';
            $dry = $this->RetrackPair($ac, $a, $b, $from, $to, false);
            if ($dry['moved'] === 0) {
                $lines[] = MHUB_Text::t('%s: continuous, nothing swapped.', $head);
                continue;
            }
            $lines[] = $head . ':';
            foreach (self::CrossWindows($dry['toggles'], $to) as [$wa, $wb, $open]) {
                $lines[] = $open ? MHUB_Text::t('   • swapped %s – today', $fmt($wa)) : MHUB_Text::t('   • swapped %s – %s', $fmt($wa), $fmt($wb));
            }
            // Auch ein unklares Paar hält den Leistungsabgleich an — der
            // braucht Zählerstände ohne Kreuzsprünge.
            $energyMoved += $dry['moved'];
            if ($dry['on'][0] === $dry['on'][1]) {
                $lines[] = MHUB_Text::t('   ⚠️ Only one of the two variables changed sides — unclear, not corrected.');
                continue;
            }
            $swapEnd = $dry['on'][0] === 1;
            if (!$apply) {
                $todo = true;
                $lines[] = MHUB_Text::t('   • %s values would be swapped between the two variables.', number_format($dry['moved'], 0, ',', '.'));
                if ($swapEnd) {
                    $lines[] = MHUB_Text::t('   • The section extends to today — in addition the two variables swap their role (name/ident) so that both keep counting without a jump. If the switch was NOT changed last, the start is probably before the checked period: enlarge the period and check again.');
                }
                continue;
            }
            $files = [];
            foreach ([$a, $b] as $vid) {
                $f = $this->BackupSeries($ac, $vid, $from, time(), 'energie');
                if ($f === null) {
                    $lines[] = MHUB_Text::t('   ❌ Backup failed — nothing changed.');
                    continue 2;
                }
                $files[] = $f;
            }
            $end = $to;
            if ($swapEnd) {
                // Erst die Rolle tauschen, dann umschreiben: ab jetzt schreibt
                // der Lesezyklus schon richtig, umgeschrieben wird nur davor.
                $va = (float)GetValue($a);
                $vb = (float)GetValue($b);
                $this->SwapIdentPair($a, $b);
                SetValueFloat($a, $vb);
                SetValueFloat($b, $va);
                $end = time() - 1;
            }
            $res = $this->RetrackPair($ac, $a, $b, $from, $end, true);
            if ($res['error'] !== null) {
                $lines[] = MHUB_Text::t('   ❌ %s Backup: %s', $res['error'], implode(', ', $files));
                continue;
            }
            $this->ReAggregate($ac, $a);
            $this->ReAggregate($ac, $b);
            $changed = true;
            $lines[] = $swapEnd
                ? MHUB_Text::t('   • %s values swapped between the two variables, role (name/ident) swapped.', number_format($res['moved'], 0, ',', '.'))
                : MHUB_Text::t('   • %s values swapped between the two variables.', number_format($res['moved'], 0, ',', '.'));
            $lines[] = MHUB_Text::t('   • Backup in the Symcon directory: %s', implode(', ', $files));
        }

        $pvid = $this->FindVarByIdent('power_total');
        $ivid = $this->FindVarByIdent('energy_import');
        $evid = $this->FindVarByIdent('energy_export');
        if (!$pvid || !$ivid || !$evid || !AC_GetLoggingStatus($ac, $pvid) || !AC_GetLoggingStatus($ac, $ivid) || !AC_GetLoggingStatus($ac, $evid)) {
            $lines[] = MHUB_Text::t('Power: no comparison possible (needs the archived total power and both energy meters).');
        } elseif (in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true)) {
            // Live an Dietmars Inexogy (12.09.2026): 14 „Abschnitte" aus je
            // 2–5 Werten, alles Zeitversatz zwischen Leistung und Zählerstand.
            $lines[] = MHUB_Text::t('Power (#%d): no comparison — with cloud meters, power and meter readings arrive with a time offset, so a direction per quarter hour is not reliable.', $pvid);
        } elseif ($energyMoved > 0) {
            $lines[] = $apply
                ? MHUB_Text::t('Power (#%d): will be compared as soon as the energy is right — please press "Check" once more now.', $pvid)
                : MHUB_Text::t('Power (#%d): will be compared as soon as the energy is right — first "Correct", then "Check" again.', $pvid);
        } else {
            $pw = $this->PowerMismatch($ac, $pvid, $ivid, $evid, $from, $to);
            if (!$pw['windows']) {
                $lines[] = MHUB_Text::t('Power (#%d): fits the direction of the energy meters throughout (%d evaluable quarter hours).', $pvid, $pw['judged']);
            } else {
                $lines[] = MHUB_Text::t('Power (#%d): opposite to the energy —', $pvid);
                foreach ($pw['windows'] as $k => [$wa, $wb, $n, $open]) {
                    $line = '   • ' . $fmt($wa) . ' – ' . ($open ? MHUB_Text::t('today') : $fmt($wb)) . ' (' . MHUB_Text::t('%s values', number_format($n, 0, ',', '.')) . ')';
                    if ($open) {
                        $lines[] = $line . MHUB_Text::t(' — extends to today and stays: first look at the direction check at the switch "Import/feed-in swapped".');
                        continue;
                    }
                    if (!$apply) {
                        $todo = true;
                        $lines[] = $line . MHUB_Text::t(' would be flipped.');
                        continue;
                    }
                    $f = $this->BackupSeries($ac, $pvid, $wa, $wb, 'leistung' . $k);
                    if ($f === null) {
                        $lines[] = $line . MHUB_Text::t(' — ❌ Backup failed, not flipped.');
                        continue;
                    }
                    $err = $this->FlipPowerWindow($ac, $pvid, $wa, $wb);
                    $lines[] = $err === null ? $line . MHUB_Text::t(' flipped. Backup: %s', $f) : $line . MHUB_Text::t(' — ❌ %s Backup: %s', $err, $f);
                    $changed = $changed || $err === null;
                }
                if ($apply) {
                    $this->ReAggregate($ac, $pvid);
                }
            }
        }
        if (!$apply) {
            $lines[] = $todo ? MHUB_Text::t('→ "Correct" does exactly this and makes a backup first (JSON file in the Symcon directory).') : MHUB_Text::t('→ Nothing to correct.');
        } elseif ($changed) {
            $this->LogMessage(MHUB_Text::t('Direction in the archive corrected — %s', str_replace("\n", ' | ', implode("\n", $lines))), KL_MESSAGE);
        }
        return implode("\n", $lines);
    }

    /**
     * Ein Zählerpaar tageweise durch RetrackStep schicken; mit $apply jeden
     * Tag mit getauschten Werten sofort umschreiben. Rückgabe ['moved' =>
     * Anzahl, 'toggles' => [[ts, Variable 0|1, Spur], …], 'on' => Endzustand,
     * 'error' => Text|null].
     */
    private function RetrackPair(int $ac, int $a, int $b, int $from, int $to, bool $apply): array
    {
        $state = ['lvl' => [$this->LastValidBefore($ac, $a, $from), $this->LastValidBefore($ac, $b, $from)], 'on' => [0, 1], 'x' => []];
        $moved = 0;
        for ($t = $from; $t < $to; $t += 86400) {
            $t2 = min($to, $t + 86400) - 1;
            $rows = [];
            foreach ([$a, $b] as $k => $vid) {
                foreach ($this->RangePoints($ac, $vid, $t, $t2) as [$ts, $v]) {
                    $rows[] = [$ts, $v, $k];
                }
            }
            usort($rows, fn($x, $y) => ($x[0] <=> $y[0]) ?: ($x[2] <=> $y[2]));
            [$state, $assign] = self::RetrackStep($state, $rows);
            $n = 0;
            foreach ($assign as $r) {
                if ($r[2] !== $r[3]) {
                    $n++;
                }
            }
            $moved += $n;
            if ($apply && $n > 0) {
                $new = [[], []];
                $orig = [[], []];
                foreach ($assign as [$ts, $v, $fromVar, $toVar]) {
                    $new[$toVar][$ts] = $v;
                    $orig[$fromVar][$ts] = $v;
                }
                $err = $this->RewriteDay($ac, [$a, $b], $t, $t2, $new, $orig);
                if ($err !== null) {
                    return ['moved' => $moved, 'toggles' => $state['x'], 'on' => $state['on'], 'error' => $err];
                }
            }
            unset($rows, $assign);
        }
        return ['moved' => $moved, 'toggles' => $state['x'], 'on' => $state['on'], 'error' => null];
    }

    /**
     * Zeilen eines Zählerpaars ihrer Spur zuordnen (frei von Symcon-Aufrufen,
     * Prüfstand). $rows aufsteigend [[ts, Wert, 0|1], …] — 0 = erste, 1 =
     * zweite Variable; Spur 0 ist die Zählerreihe, die Variable 0 zu Beginn
     * trug. $state: 'lvl' = letzter Stand je Spur, 'on' = Spur, die jede
     * Variable gerade trägt, 'x' = Wechsel [ts, Variable, neue Spur].
     * Eine Variable wechselt die Spur nur, wenn ihr Wert auf den Stand der
     * anderen Spur springt (±1 %), der Sprung mindestens die halbe
     * Spurdistanz ist und die Spuren deutlich getrennt liegen (≥ 5 %). Ein
     * Einheitenwechsel (Wh→kWh, Solarpark 09/2026) oder ein Zählertausch ist
     * so kein Wechsel. Nullwerte bleiben, wo sie sind. Rückgabe [$state,
     * [[ts, Wert, von Variable, zu Variable], …]].
     */
    private static function RetrackStep(array $state, array $rows): array
    {
        $assign = [];
        foreach ($rows as [$ts, $v, $var]) {
            if (!is_finite($v) || $v <= 0) {
                $assign[] = [$ts, $v, $var, $var];
                continue;
            }
            $own = $state['on'][$var];
            $oth = 1 - $own;
            $lo = $state['lvl'][$own];
            $lt = $state['lvl'][$oth];
            if ($lo !== null && $lt !== null) {
                $sep = abs($lo - $lt);
                if ($sep >= 0.05 * max($lo, $lt) && abs($v - $lt) <= 0.01 * $lt && abs($v - $lo) >= 0.5 * $sep) {
                    $state['on'][$var] = $oth;
                    $state['x'][] = [$ts, $var, $oth];
                    $own = $oth;
                }
            }
            $state['lvl'][$own] = $v;
            $assign[] = [$ts, $v, $var, $own];
        }
        return [$state, $assign];
    }

    /** Vertauschte Abschnitte aus den Wechseln: [[von, bis, reicht bis heute], …]. */
    private static function CrossWindows(array $toggles, int $to): array
    {
        $use = array_values(array_filter($toggles, fn($x) => $x[1] === 0));
        $crossed = 1;
        if (!$use) {
            $use = array_values(array_filter($toggles, fn($x) => $x[1] === 1));
            $crossed = 0;
        }
        $win = [];
        $open = null;
        foreach ($use as [$ts, $var, $track]) {
            if ($track === $crossed && $open === null) {
                $open = $ts;
            } elseif ($track !== $crossed && $open !== null) {
                $win[] = [$open, $ts, false];
                $open = null;
            }
        }
        if ($open !== null) {
            $win[] = [$open, $to, true];
        }
        return $win;
    }

    /**
     * Gegenläufige Leistung finden: je Viertelstunde Bezug − Einspeisung aus
     * den Zählerständen gegen den Mittelwert der archivierten Leistung (beide
     * „+ = Bezug"). Rückgabe ['windows' => [[von, bis, Anzahl Werte, reicht
     * bis heute], …], 'judged' => auswertbare Viertelstunden].
     */
    private function PowerMismatch(int $ac, int $pvid, int $ivid, int $evid, int $from, int $to): array
    {
        $toW = $this->ReadPropertyBoolean('EnergyUnitWh') ? 4.0 : 4000.0; // Zunahme je Viertelstunde → W
        $cp = $this->LastValueBefore($ac, $pvid, $from);
        $ci = $this->LastValidBefore($ac, $ivid, $from);
        $ce = $this->LastValidBefore($ac, $evid, $from);
        $verdicts = [];
        $nets = [];
        $judged = 0;
        for ($t = $from; $t < $to; $t += 86400) {
            $t2 = min($to, $t + 86400);
            $pp = $this->RangePoints($ac, $pvid, $t, $t2 - 1);
            $pi = $this->RangePoints($ac, $ivid, $t, $t2 - 1);
            $pe = $this->RangePoints($ac, $evid, $t, $t2 - 1);
            $pm = self::SegmentMeans($pp, $cp, $t, $t2, 900);
            $hi = self::SampleHold($pi, $ci, $t, $t2, 900);
            $he = self::SampleHold($pe, $ce, $t, $t2, 900);
            for ($q = $t; $q + 900 <= $t2; $q += 900) {
                $verdict = 0;
                if (isset($pm[$q]) && $hi[$q] !== null && $hi[$q + 900] !== null && $he[$q] !== null && $he[$q + 900] !== null) {
                    $dI = $hi[$q + 900] - $hi[$q];
                    $dE = $he[$q + 900] - $he[$q];
                    if ($dI >= 0 && $dE >= 0) {
                        $nets[$q] = ($dI - $dE) * $toW;
                        $verdict = self::BucketVerdict($nets[$q], $pm[$q], self::DIR_MIN_W);
                    }
                }
                if ($verdict !== 0) {
                    $judged++;
                }
                $verdicts[] = [$q, $verdict];
            }
            if ($pp) {
                $cp = $pp[count($pp) - 1][1];
            }
            $ci = $hi[$t2] ?? $ci;
            $ce = $he[$t2] ?? $ce;
            unset($pp, $pi, $pe, $pm, $hi, $he);
        }
        $windows = [];
        // Bis 12 h überbrücken: Nachts fließt oft zu wenig Leistung für eine
        // Aussage. Live an Dietmars PAC2200 (26./27.07.2026) zerfiel ein
        // falsch gedrehter Abschnitt sonst in zwei, die Nacht blieb stehen.
        foreach (self::FindMismatchWindows($verdicts, 900, 43200, 2) as [$wa, $wb]) {
            $open = $wb >= $to;
            $sa = $this->RefineEdge($ac, $pvid, $nets, $wa, true);
            $sb = $open ? $wb : $this->RefineEdge($ac, $pvid, $nets, $wb, false);
            $windows[] = [$sa, $sb, $this->CountRows($ac, $pvid, $sa, $sb - 1), $open];
        }
        return ['windows' => $windows, 'judged' => $judged];
    }

    /** Viertelstunde einstufen: +1 passt, −1 gegenläufig, 0 zu wenig Leistung für eine Aussage. */
    private static function BucketVerdict(float $netW, float $powerW, float $minW): int
    {
        if (abs($netW) < $minW || abs($powerW) < $minW) {
            return 0;
        }
        return ($netW > 0) === ($powerW > 0) ? 1 : -1;
    }

    /**
     * Zusammenhängende gegenläufige Viertelstunden ([[Beginn, Urteil], …]
     * aufsteigend) zu Abschnitten [[von, bis], …]. Unklare Stücke bis $bridge
     * Sekunden werden überbrückt, eine passende Viertelstunde beendet den
     * Abschnitt. Mindestens $minBuckets gegenläufige Viertelstunden — einzelne
     * Ausreißer sind kein Umschalten.
     */
    private static function FindMismatchWindows(array $verdicts, int $bucket, int $bridge, int $minBuckets): array
    {
        $out = [];
        $cur = null;
        $close = function () use (&$cur, &$out, $minBuckets) {
            if ($cur !== null && $cur[2] >= $minBuckets) {
                $out[] = [$cur[0], $cur[1]];
            }
            $cur = null;
        };
        foreach ($verdicts as [$q, $v]) {
            if ($v === -1) {
                if ($cur !== null && $q - $cur[1] > $bridge) {
                    $close();
                }
                if ($cur === null) {
                    $cur = [$q, $q + $bucket, 1];
                } else {
                    $cur[1] = $q + $bucket;
                    $cur[2]++;
                }
            } elseif ($v === 1) {
                $close();
            }
        }
        $close();
        return $out;
    }

    /** Minutengenaue Kante eines gegenläufigen Abschnitts aus den Leistungswerten ±15 min um die Viertelstunden-Kante. */
    private function RefineEdge(int $ac, int $pvid, array $nets, int $edge, bool $start): int
    {
        $pts = [];
        foreach ($this->RangePoints($ac, $pvid, $edge - 900, $edge + 899) as [$ts, $p]) {
            $net = $nets[intdiv($ts, 900) * 900] ?? null;
            if ($net === null || abs($net) < self::DIR_MIN_W || abs($p) < self::DIR_MIN_W) {
                continue;
            }
            $pts[] = [$ts, ($net > 0) !== ($p > 0)];
        }
        return self::ChangePoint($pts, $start) ?? $edge;
    }

    /**
     * Umschaltzeitpunkt: $pts aufsteigend [[ts, gegenläufig?], …]. Am Anfang
     * eines Abschnitts (vorher passend, danach gegenläufig) bzw. am Ende
     * (umgekehrt) der Zeitpunkt, der die meisten Werte richtig einteilt.
     * Rückgabe: erster Zeitstempel der zweiten Hälfte, null ohne Werte.
     */
    private static function ChangePoint(array $pts, bool $startEdge): ?int
    {
        $n = count($pts);
        if ($n === 0) {
            return null;
        }
        $total = 0;
        foreach ($pts as $p) {
            $total += $p[1] ? 1 : 0;
        }
        $best = -1;
        $bestK = 0;
        $flipBefore = 0;
        for ($k = 0; $k <= $n; $k++) {
            $afterFlip = $total - $flipBefore;
            $score = $startEdge
                ? ($k - $flipBefore) + $afterFlip
                : $flipBefore + (($n - $k) - $afterFlip);
            if ($score > $best) {
                $best = $score;
                $bestK = $k;
            }
            if ($k < $n && $pts[$k][1]) {
                $flipBefore++;
            }
        }
        return $bestK < $n ? $pts[$bestK][0] : $pts[$n - 1][0] + 1;
    }

    /** Leistung in [von, bis) drehen, tageweise mit Wiederherstellung bei Fehlern. */
    private function FlipPowerWindow(int $ac, int $pvid, int $wa, int $wb): ?string
    {
        for ($t = $wa; $t < $wb; $t += 86400) {
            $t2 = min($wb - 1, $t + 86399);
            $orig = [];
            $new = [];
            foreach ($this->RangePoints($ac, $pvid, $t, $t2) as [$ts, $v]) {
                $orig[$ts] = $v;
                $new[$ts] = -$v;
            }
            if (!$orig) {
                continue;
            }
            $err = $this->RewriteDay($ac, [$pvid], $t, $t2, [$new], [$orig]);
            if ($err !== null) {
                return $err;
            }
        }
        return null;
    }

    /** Einen Tag mehrerer Variablen ersetzen; lehnt das Archiv ab, wird der Tag im Originalzustand wiederhergestellt. */
    private function RewriteDay(int $ac, array $vids, int $t, int $t2, array $new, array $orig): ?string
    {
        foreach ($vids as $vid) {
            AC_DeleteVariableData($ac, $vid, $t, $t2);
        }
        foreach ($vids as $k => $vid) {
            if ($this->AddRowsRetry($ac, $vid, $new[$k])) {
                continue;
            }
            foreach ($vids as $vid2) {
                AC_DeleteVariableData($ac, $vid2, $t, $t2);
            }
            $ok = true;
            foreach ($vids as $k2 => $vid2) {
                $ok = $this->AddRowsRetry($ac, $vid2, $orig[$k2]) && $ok;
            }
            return $ok
                ? MHUB_Text::t('The archive rejected the backfill for %s — this day was restored unchanged, later days were not processed.', date('d.m.Y', $t))
                : MHUB_Text::t('The archive rejected the backfill for %s — the restore failed as well, please use the backup file.', date('d.m.Y', $t));
        }
        return null;
    }

    /**
     * Werte [ts => Wert] ins Archiv nachtragen. Liegen gerade geschriebene
     * Werte noch im Schreibpuffer, lehnt Symcon neuere Zeitstempel ab
     * („Kann Daten mit einem neueren Zeitstempel … nicht hinzufügen") —
     * dann kurz warten und erneut versuchen.
     */
    private function AddRowsRetry(int $ac, int $vid, array $byTs): bool
    {
        if (!$byTs) {
            return true;
        }
        ksort($byTs);
        $rows = [];
        foreach ($byTs as $ts => $v) {
            $rows[] = ['TimeStamp' => (int)$ts, 'Value' => (float)$v];
        }
        for ($try = 0; $try < 10; $try++) {
            if (@AC_AddLoggedValues($ac, $vid, $rows)) {
                return true;
            }
            usleep(2000000);
        }
        return false;
    }

    /** Archivwerte [von, bis] als JSON ins Symcon-Verzeichnis sichern (tageweise gestreamt). Rückgabe Dateiname oder null. */
    private function BackupSeries(int $ac, int $vid, int $from, int $to, string $tag): ?string
    {
        $file = IPS_GetKernelDir() . 'meterhub_sicherung_' . $vid . '_' . $tag . '_' . date('Ymd_His') . '.json';
        $fh = @fopen($file, 'w');
        if ($fh === false) {
            return null;
        }
        fwrite($fh, '[');
        $first = true;
        for ($t = $from; $t <= $to; $t += 86400) {
            foreach ($this->RangePoints($ac, $vid, $t, min($to, $t + 86399)) as $p) {
                fwrite($fh, ($first ? '' : ',') . json_encode($p));
                $first = false;
            }
        }
        fwrite($fh, ']');
        fclose($fh);
        return basename($file);
    }

    /** Archivwerte [von, bis] (höchstens ein Tag, Grenze 50 000 Zeilen) aufsteigend als [[ts, Wert], …]. */
    private function RangePoints(int $ac, int $vid, int $from, int $to): array
    {
        $out = [];
        foreach (array_reverse(AC_GetLoggedValues($ac, $vid, $from, $to, 0)) as $r) {
            $out[] = [(int)$r['TimeStamp'], (float)$r['Value']];
        }
        return $out;
    }

    /**
     * Verdichtung einer Variable neu bilden. Symcon erlaubt nur EINE
     * Neuberechnung zur selben Zeit — der zweite Aufruf direkt danach
     * scheitert mit „Eine andere Aggregation wird aktuell durchgeführt"
     * (live 13.09.2026 bei jedem Inexogy-Nachtrag mit mehr als einer neuen
     * Reihe; die Verdichtung der übrigen Variablen blieb veraltet). Dann kommt
     * die Variable in eine Warteschlange, die ReadSlow() nach und nach abarbeitet.
     */
    private function ReAggregate(int $ac, int $vid): void
    {
        if ($this->TryReAggregate($ac, $vid)) {
            return;
        }
        $q = json_decode((string)$this->ReadAttributeString('ReAggQueue'), true);
        $q = is_array($q) ? $q : [];
        if (!in_array($vid, $q, true)) {
            $q[] = $vid;
            $this->WriteAttributeString('ReAggQueue', (string)json_encode($q));
        }
    }

    private function TryReAggregate(int $ac, int $vid): bool
    {
        error_clear_last();
        $ok = @AC_ReAggregateVariable($ac, $vid);
        return $ok !== false && error_get_last() === null;
    }

    /** Wartende Neuberechnungen: je Lesezyklus höchstens eine — gleichzeitig läuft ohnehin nur eine. */
    private function ProcessReAggQueue(): void
    {
        $q = json_decode((string)$this->ReadAttributeString('ReAggQueue'), true);
        if (!is_array($q) || !$q) {
            return;
        }
        $acs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($acs) === 0) {
            return;
        }
        $vid = (int)$q[0];
        if (!IPS_VariableExists($vid) || $this->TryReAggregate($acs[0], $vid)) {
            array_shift($q);
            $this->WriteAttributeString('ReAggQueue', (string)json_encode($q));
        }
    }

    private function CountRows(int $ac, int $vid, int $from, int $to): int
    {
        $n = 0;
        for ($t = $from; $t <= $to; $t += 86400) {
            $n += count(AC_GetLoggedValues($ac, $vid, $t, min($to, $t + 86399), 0));
        }
        return $n;
    }

    /** Letzter gültiger Zählerstand (> 0) vor $t, null wenn keiner. */
    private function LastValidBefore(int $ac, int $vid, int $t): ?float
    {
        foreach (AC_GetLoggedValues($ac, $vid, 0, $t - 1, 20) as $r) {
            $v = (float)$r['Value'];
            if (is_finite($v) && $v > 0) {
                return $v;
            }
        }
        return null;
    }

    /** Letzter archivierter Wert vor $t (auch negativ), null wenn keiner. */
    private function LastValueBefore(int $ac, int $vid, int $t): ?float
    {
        $r = AC_GetLoggedValues($ac, $vid, 0, $t - 1, 1);
        return $r ? (float)$r[0]['Value'] : null;
    }

    /**
     * Zeitgewichtete Mittelwerte je Abschnitt [$from, $to) mit Schrittweite
     * $step: jeder Wert gilt bis zum nächsten, $carry ist der Wert vor $from.
     * Rückgabe [Abschnittsbeginn => Mittelwert] (nur Abschnitte mit Wert).
     */
    private static function SegmentMeans(array $pts, ?float $carry, int $from, int $to, int $step): array
    {
        $acc = [];
        $t = $from;
        $v = $carry;
        $pts[] = [$to, null];
        foreach ($pts as [$ts, $nv]) {
            $ts = min(max((int)$ts, $from), $to);
            if ($v !== null && is_finite($v)) {
                $a = $t;
                while ($a < $ts) {
                    $b0 = $from + intdiv($a - $from, $step) * $step;
                    $b = min($ts, $b0 + $step);
                    $acc[$b0][0] = ($acc[$b0][0] ?? 0.0) + $v * ($b - $a);
                    $acc[$b0][1] = ($acc[$b0][1] ?? 0) + ($b - $a);
                    $a = $b;
                }
            }
            if ($ts > $t) {
                $t = $ts;
            }
            if ($nv !== null) {
                $v = (float)$nv;
            }
        }
        $out = [];
        foreach ($acc as $b0 => [$s, $w]) {
            if ($w > 0) {
                $out[$b0] = $s / $w;
            }
        }
        return $out;
    }

    /** Zählerstand an den Grenzen $from, $from+$step, …, $to (letzter gültiger Wert davor, $carry vor $from). */
    private static function SampleHold(array $pts, ?float $carry, int $from, int $to, int $step): array
    {
        $out = [];
        $i = 0;
        $n = count($pts);
        $v = $carry;
        for ($q = $from; $q <= $to; $q += $step) {
            while ($i < $n && $pts[$i][0] <= $q) {
                if (is_finite($pts[$i][1]) && $pts[$i][1] > 0) {
                    $v = (float)$pts[$i][1];
                }
                $i++;
            }
            $out[$q] = $v;
        }
        return $out;
    }

    /**
     * Diagnose-Vertrag 1.0 (0.27.0, Anregung Dashboard 12.09.2026, Format wie
     * IHUBMON_GetDiagnostics): MeterHub bewertet, der Konsument zeigt nur an.
     * Je Netz-Zuordnung ein Eintrag `meter_direction` — läuft die Leistung
     * wie eine zweite Quelle? Vergleichsquellen der Reihe nach: andere
     * MeterHub-Netzzähler (gleichläufig), InverterHub-Netzmessung (dort
     * „+ = Einspeisung", also gegenläufig), hilfsweise die PV-Erzeugung
     * (Netzleistung muss fallen, wenn PV steigt — nur Hinweis). Ergebnis
     * 30 min zwischengespeichert; ein neuer Befund landet einmal im Log.
     * `correlation` ist immer NORMIERT: +1 = Richtung passt, −1 = verkehrt —
     * bei 'opposite' und 'pv' also die umgekehrte Rohkorrelation (Netz ↔ PV
     * roh −0,998 = correlation +0,998). Alle Schwellen gelten für diesen Wert.
     */
    public function GetDiagnostics(): array
    {
        if (!$this->InstanceReady()) {
            return ['contractVersion' => self::DIAG_CONTRACT, 'instanceID' => $this->InstanceID, 'ready' => false, 'entries' => []];
        }
        $cache = json_decode((string)$this->ReadAttributeString('DirectionDiag'), true);
        if (is_array($cache) && ($cache['contractVersion'] ?? '') === self::DIAG_CONTRACT && ($cache['checkedAt'] ?? 0) > time() - 1800) {
            return $cache;
        }
        $fresh = $this->ComputeDiagnostics();
        $old = [];
        foreach ((is_array($cache) ? ($cache['entries'] ?? []) : []) as $e) {
            $old[$e['slot'] ?? ''] = $e['level'] ?? null;
        }
        foreach ($fresh['entries'] as $e) {
            if (in_array($e['level'], ['auffaellig', 'kritisch'], true) && ($old[$e['slot']] ?? null) !== $e['level']) {
                $this->LogMessage($e['label'] . ' — ' . $e['reason'], KL_WARNING);
            }
        }
        $this->WriteAttributeString('DirectionDiag', (string)json_encode($fresh));
        return $fresh;
    }

    private function ComputeDiagnostics(): array
    {
        $now = time();
        // 1.1 = independent/referenceIDs je Eintrag, PV als Summe mehrerer Zähler.
        $out = ['contractVersion' => self::DIAG_CONTRACT, 'instanceID' => $this->InstanceID, 'checkedAt' => $now, 'entries' => []];
        $acs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($acs) === 0) {
            return $out;
        }
        $ac = $acs[0];
        $from = intdiv($now - 172800, 300) * 300;
        $refs = null;
        foreach ($this->FunctionAssignments() as $a) {
            if ($a['key'] !== 'grid') {
                continue;
            }
            $pid = (int)$this->FindVarByIdent($a['power']);
            if (!$pid) {
                continue;
            }
            $entry = [
                // Instanzname statt Funktionsname — sonst stehen im Dashboard
                // mehrere gleiche Zeilen „Richtung Netzanschluss" (Einwand 12.09.2026).
                'type' => 'meter_direction', 'slot' => $a['slot'],
                'label' => MHUB_Text::t('Direction %s', IPS_GetName($this->InstanceID)) . ($a['slot'] === 'total' ? '' : ' ' . $a['slot']),
                'level' => null, 'threshold' => null, 'reason' => '', 'independent' => null, 'referenceIDs' => [],
                'powerID' => $pid, 'referencePowerID' => 0, 'referenceLabel' => '', 'relation' => '',
                'correlation' => null, 'samples' => 0, 'checkedAt' => $now,
            ];
            if (in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true)) {
                $entry['reason'] = MHUB_Text::t('Cloud meter: power arrives with a time offset — no direction check.');
                $out['entries'][] = $entry;
                continue;
            }
            if (!AC_GetLoggingStatus($ac, $pid)) {
                $entry['reason'] = MHUB_Text::t('The power is not archived — no direction check possible.');
                $out['entries'][] = $entry;
                continue;
            }
            $own = self::SegmentMeans($this->LoadArchivePoints($ac, $pid, $from, $now), $this->LastValueBefore($ac, $pid, $from), $from, $now, 300);
            $refs ??= $this->DirectionReferences();
            foreach ($refs as $r) {
                [$rlabel, $relation] = [$r['label'], $r['relation']];
                $ids = array_values(array_filter($r['ids'], fn($x) => $x !== $pid && IPS_VariableExists($x) && AC_GetLoggingStatus($ac, $x)));
                if (!$ids) {
                    continue;
                }
                $rid = $ids[0];
                $ref = [];
                foreach ($ids as $x) {
                    foreach (self::SegmentMeans($this->LoadArchivePoints($ac, $x, $from, $now), $this->LastValueBefore($ac, $x, $from), $from, $now, 300) as $k => $w) {
                        $ref[$k] = ($ref[$k] ?? 0.0) + ($relation === 'pv' ? abs($w) : $w);
                    }
                }
                [$c, $n] = $relation === 'pv' ? self::PearsonPairs($own, $ref, 300.0) : self::CosinePairs($own, $ref, self::DIR_MIN_W);
                if ($n < 24) {
                    continue; // weniger als 2 h gemeinsame Messwerte — nächste Quelle
                }
                $rel = $relation === 'same' ? $c : -$c;
                [$level, $th] = self::DirectionLevel($rel, $relation);
                $pct = (int)round($rel * 100);
                if ($relation === 'pv') {
                    // $rlabel beginnt schon mit „PV-Erzeugung" (live 12.09.2026: „PV-Erzeugung (PV-Erzeugung (20 Zähler))").
                    if ($level === 'normal') {
                        $reason = MHUB_Text::t('The grid power falls when the %s rises — direction fits (agreement %d %% over %d five-minute values of the last 48 h).', $rlabel, $pct, $n);
                    } elseif ($level === null) {
                        $reason = MHUB_Text::t('No clear statement from the comparison with the %s (agreement %d %%).', $rlabel, $pct);
                    } elseif ($level === 'kritisch') {
                        $reason = MHUB_Text::t('The grid power rises with the %s (agreement %d %%) — the meter seems to measure the wrong way round. Check the switch "Import/feed-in swapped".', $rlabel, $pct);
                    } else {
                        $reason = MHUB_Text::t('The grid power rises with the %s (agreement %d %%) — this points to a wrong direction. Check the switch "Import/feed-in swapped". Only a hint: consumers that follow the PV can look like this, too.', $rlabel, $pct);
                    }
                } else {
                    if ($level === 'normal') {
                        $reason = MHUB_Text::t('Direction fits %s (agreement %d %% over %d five-minute values of the last 48 h).', $rlabel, $pct, $n);
                    } elseif ($level === null) {
                        $reason = MHUB_Text::t('No clear statement in the comparison with %s (agreement %d %%).', $rlabel, $pct);
                    } else {
                        $reason = MHUB_Text::t('The power runs opposite to %s (agreement %d %%) — the meter seems to measure the wrong way round. Check the switch "Import/feed-in swapped"; it is also possible that the comparison source is wrong.', $rlabel, $pct);
                    }
                }
                if (!$r['independent']) {
                    $reason .= MHUB_Text::t(' Note: comparison with a meter of the same module — if both are reversed the same way, this cannot be detected.');
                }
                $entry = array_merge($entry, [
                    'level' => $level, 'threshold' => $th, 'reason' => $reason, 'independent' => $r['independent'], 'referenceIDs' => $ids,
                    'referencePowerID' => $rid, 'referenceLabel' => $rlabel, 'relation' => $relation,
                    'correlation' => round($rel, 2), 'samples' => $n,
                ]);
                break;
            }
            if ($entry['reason'] === '') {
                $entry['reason'] = MHUB_Text::t('No comparison source with enough measured values in the last 48 h (another grid meter, an inverter with grid measurement, or PV generation in the group).');
            }
            $out['entries'][] = $entry;
        }
        // Rollen-Plausibilität (1.2): nur für Unterzähler (Verbraucher/Erzeuger),
        // nicht für "Netz-/NAP-Zähler" — der darf naturgemäß in beide Richtungen
        // messen. Instanzweit, nicht je Funktionszuordnung (Role ist keine
        // Zuordnung, sondern eine Eigenschaft der Instanz selbst).
        $role = $this->ReadPropertyString('Role');
        if ($role === 'consumption' || $role === 'production') {
            $pid = (int)$this->FindVarByIdent('power_total');
            if ($pid > 0) {
                $out['entries'][] = $this->RolePlausibility($pid, $role);
            }
        }
        return $out;
    }

    /** Vergleichsquellen [[Leistungs-ID, Bezeichnung, 'same'|'opposite'|'pv'], …] in der Reihenfolge ihrer Aussagekraft. */
    private function DirectionReferences(): array
    {
        // Reihenfolge nach Unabhängigkeit (Dashboards Einwand 12.09.2026):
        // Zwei Netzzähler aus diesem Modul können GEMEINSAM verkehrt stehen —
        // im Solarpark hatten beide NAPs zugleich PowerInvert falsch, der
        // gegenseitige Vergleich hätte trotzdem „passt" gemeldet. Deshalb
        // zuerst Wechselrichter-Netzmessung und PV-Erzeugung, ein anderer
        // MeterHub-Netzzähler nur als Notlösung mit Hinweis.
        $sameGrid = [];
        $pv = [];
        $pvNames = [];
        foreach ([['{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}', 'MHUB_GetFunctions'], ['{ADF18291-2E60-4354-92F5-B96863C127C8}', 'MHUBV_GetFunctions']] as [$guid, $fn]) {
            if (!function_exists($fn)) {
                continue;
            }
            foreach (IPS_GetInstanceListByModuleID($guid) as $iid) {
                if ($iid === $this->InstanceID || IPS_GetInstance($iid)['InstanceStatus'] !== 102) {
                    continue;
                }
                $f = json_decode((string)@$fn($iid), true);
                if (!is_array($f) || ($f['latency'] ?? 'realtime') !== 'realtime') {
                    continue;
                }
                foreach ($f['assignments'] ?? [] as $as) {
                    $pid = (int)($as['powerID'] ?? 0);
                    if ($pid <= 0) {
                        continue;
                    }
                    if (($as['function'] ?? '') === 'pv') {
                        $pv[] = $pid;
                        $pvNames[] = IPS_GetName($iid);
                    } elseif (($as['function'] ?? '') === 'grid' && $fn === 'MHUB_GetFunctions') {
                        $sameGrid[] = ['ids' => [$pid], 'label' => IPS_GetName($iid), 'relation' => 'same', 'independent' => false];
                    }
                }
            }
        }
        $refs = [];
        $ihub = [];
        if (function_exists('IHUB_GetFunctions') && @IPS_ModuleExists('{BBE2C593-1A91-426D-A714-29A9C7E87589}')) {
            foreach (IPS_GetInstanceListByModuleID('{BBE2C593-1A91-426D-A714-29A9C7E87589}') as $iid) {
                $f = @IHUB_GetFunctions($iid);
                if (is_array($f)) {
                    $ihub[] = [$iid, $f];
                }
            }
        }
        foreach ($ihub as [$iid, $f]) {
            if ((int)($f['gridPowerID'] ?? 0) > 0) {
                $refs[] = ['ids' => [(int)$f['gridPowerID']], 'label' => MHUB_Text::t('%s (grid measurement)', IPS_GetName($iid)), 'relation' => 'opposite', 'independent' => true];
            }
        }
        foreach ($ihub as [$iid, $f]) {
            if ((int)($f['pvPowerID'] ?? 0) > 0) {
                $pv[] = (int)$f['pvPowerID'];
                $pvNames[] = IPS_GetName($iid);
            }
        }
        // PV als Summe der Beträge: egal, wie herum ein PV-Zähler eingestellt
        // ist — damit ist diese Quelle auch aus demselben Modul unabhängig.
        $pv = array_slice(array_values(array_unique($pv)), 0, 20);
        if ($pv) {
            $refs[] = ['ids' => $pv, 'label' => count($pv) === 1 ? MHUB_Text::t('PV generation %s', $pvNames[0]) : MHUB_Text::t('PV generation (%d meters)', count($pv)), 'relation' => 'pv', 'independent' => true];
        }
        return array_merge($refs, $sameGrid);
    }

    /** Gleichlauf zweier Leistungsreihen [Zeit => W] (Kosinus, vorzeichenempfindlich), nur Paare mit |W| ≥ $minW. Rückgabe [Wert −1…+1, Anzahl]. */
    private static function CosinePairs(array $a, array $b, float $minW): array
    {
        $sab = 0.0;
        $saa = 0.0;
        $sbb = 0.0;
        $n = 0;
        foreach ($a as $k => $x) {
            if (!isset($b[$k]) || abs($x) < $minW || abs($b[$k]) < $minW) {
                continue;
            }
            $y = $b[$k];
            $sab += $x * $y;
            $saa += $x * $x;
            $sbb += $y * $y;
            $n++;
        }
        return [($saa > 0 && $sbb > 0) ? $sab / sqrt($saa * $sbb) : 0.0, $n];
    }

    /** Korrelation (Pearson) Netzleistung ↔ PV-Erzeugung, nur Zeiten mit PV ≥ $minPv. Rückgabe [Wert −1…+1, Anzahl]. */
    private static function PearsonPairs(array $grid, array $pv, float $minPv): array
    {
        $xs = [];
        $ys = [];
        foreach ($grid as $k => $x) {
            if (isset($pv[$k]) && $pv[$k] >= $minPv) {
                $xs[] = $x;
                $ys[] = $pv[$k];
            }
        }
        $n = count($xs);
        if ($n < 2) {
            return [0.0, $n];
        }
        $mx = array_sum($xs) / $n;
        $my = array_sum($ys) / $n;
        $sxy = 0.0;
        $sxx = 0.0;
        $syy = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dx = $xs[$i] - $mx;
            $dy = $ys[$i] - $my;
            $sxy += $dx * $dy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
        }
        return [($sxx > 0 && $syy > 0) ? $sxy / sqrt($sxx * $syy) : 0.0, $n];
    }

    /**
     * Bewertung aus der Übereinstimmung (+1 passt, −1 gegenläufig).
     * Netzquelle ('same'/'opposite'): ab ±0,6. PV ('pv', Korrelation mit
     * Verbrauchern verwaschen): ab ±0,3 normal/auffällig, erst ab −0,8
     * kritisch — so eindeutig ist es nur, wenn kaum Verbrauch dazwischen
     * liegt (Solarpark) oder die Richtung wirklich verkehrt ist.
     */
    private static function DirectionLevel(float $rel, string $relation): array
    {
        if ($relation === 'pv') {
            if ($rel >= 0.3) {
                return ['normal', 0.3];
            }
            if ($rel <= -0.8) {
                return ['kritisch', 0.3];
            }
            return [$rel <= -0.3 ? 'auffaellig' : null, 0.3];
        }
        if ($rel >= 0.6) {
            return ['normal', 0.6];
        }
        return [$rel <= -0.6 ? 'kritisch' : null, 0.6];
    }

    /** Zeile unter dem Schalter „Bezug/Einspeisung vertauscht". */
    private function DirectionFormText(): string
    {
        try {
            $d = $this->GetDiagnostics();
        } catch (\Throwable $e) {
            return MHUB_Text::t('🧭 Direction check: not possible right now (%s).', $e->getMessage());
        }
        $parts = [];
        foreach ($d['entries'] ?? [] as $e) {
            if (($e['type'] ?? '') !== 'meter_direction') {
                continue;
            }
            $icon = ['normal' => '✅', 'auffaellig' => '⚠️', 'kritisch' => '❌'][$e['level'] ?? ''] ?? 'ℹ️';
            $parts[] = '🧭 ' . $e['label'] . ': ' . $icon . ' ' . $e['reason'];
        }
        return $parts ? implode("\n", $parts) : MHUB_Text::t('🧭 Direction check: only for meters with the function "Grid connection".');
    }

    /** Zeile unter der Rollen-Auswahl (Feld „Role"). */
    private function RolePlausibilityFormText(): string
    {
        try {
            $d = $this->GetDiagnostics();
        } catch (\Throwable $e) {
            return '';
        }
        foreach ($d['entries'] ?? [] as $e) {
            if (($e['type'] ?? '') !== 'role_plausibility') {
                continue;
            }
            $icon = ['normal' => '✅', 'auffaellig' => '⚠️', 'kritisch' => '❌'][$e['level'] ?? ''] ?? 'ℹ️';
            return $icon . ' ' . $e['reason'];
        }
        return ''; // Rolle "Netz-/NAP-Zähler" oder gerade keine Aussage möglich — dann keine Zeile.
    }

    /**
     * Plausibilitätsprüfung für Unterzähler (Rolle „Verbraucher"/„Erzeuger",
     * nicht „Netz-/NAP-Zähler"): Ein reiner Verbraucher oder Erzeuger sollte
     * über die Zeit überwiegend nur in EINE Richtung messen. Zeigt der Zähler
     * über einen nennenswerten Anteil der Zeit SOWOHL deutlich positive ALS
     * AUCH deutlich negative Leistung, passt die gewählte Rolle vermutlich
     * nicht (eher ein Netzanschluss/bidirektionaler Zähler) oder die
     * Verkabelung ist falsch gepolt. Bewusst konventionsunabhängig: ob ein
     * Erzeuger seine Rohwerte positiv oder negativ liefert, ist
     * herstellerabhängig (siehe CLAUDE.md „Registerkarten: erst messen, dann
     * glauben") — geprüft wird nur, ob EINE Richtung überwiegt, nicht welche.
     * Dietmars Auftrag 14.09.2026 (Nachfrage zur dritten Rollen-Option, zuvor
     * war „Role" ein wirkungsloses Formularfeld).
     */
    private function RolePlausibility(int $pid, string $role): array
    {
        $now = time();
        $entry = [
            'type' => 'role_plausibility', 'slot' => 'total',
            'label' => MHUB_Text::t('Role %s', IPS_GetName($this->InstanceID)),
            'level' => null, 'threshold' => self::ROLE_SHARE_AUFFAELLIG, 'reason' => '',
            'role' => $role, 'powerID' => $pid,
            'positiveShare' => null, 'negativeShare' => null, 'samples' => 0, 'checkedAt' => $now,
        ];
        $acs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($acs) === 0) {
            $entry['reason'] = MHUB_Text::t('No archive module found — no check possible.');
            return $entry;
        }
        $ac = $acs[0];
        if (!AC_GetLoggingStatus($ac, $pid)) {
            $entry['reason'] = MHUB_Text::t('The power is not archived — no check possible.');
            return $entry;
        }
        $from = intdiv($now - 172800, 300) * 300;
        $means = self::SegmentMeans($this->LoadArchivePoints($ac, $pid, $from, $now), $this->LastValueBefore($ac, $pid, $from), $from, $now, 300);
        $n = count($means);
        if ($n < 24) {
            $entry['reason'] = MHUB_Text::t('Still too few measured values for a statement (at least 2 h needed).');
            return $entry;
        }
        $pos = 0;
        $neg = 0;
        foreach ($means as $w) {
            if ($w > self::DIR_MIN_W) {
                $pos++;
            } elseif ($w < -self::DIR_MIN_W) {
                $neg++;
            }
        }
        $posShare = $pos / $n;
        $negShare = $neg / $n;
        $entry['positiveShare'] = round($posShare, 2);
        $entry['negativeShare'] = round($negShare, 2);
        $entry['samples'] = $n;
        $roleLabel = $role === 'production' ? MHUB_Text::t('generator') : MHUB_Text::t('consumer');
        $entry['level'] = self::RoleShareLevel($posShare, $negShare);
        if ($entry['level'] !== 'normal') {
            $entry['reason'] = MHUB_Text::t('Marked as "Sub-meter / %s", but over the last 48 h it measures both clearly positive (%d %% of the time) and clearly negative power (%d %% of the time) — atypical for a pure %s. "Grid/connection-point meter" probably fits better, or the meter is wired with the wrong polarity.', $roleLabel, round($posShare * 100), round($negShare * 100), $roleLabel);
        } else {
            $entry['reason'] = MHUB_Text::t('Over the last 48 h it measures predominantly in one direction, which fits "Sub-meter / %s".', $roleLabel);
        }
        return $entry;
    }

    /**
     * Bewertung reiner Anteilswerte (frei von Symcon-Aufrufen, siehe
     * RolePlausibility()): 'kritisch'/'auffaellig' nur wenn BEIDE Anteile die
     * jeweilige Schwelle erreichen — eine reine Verbrauchs- oder
     * Erzeugungsrichtung mit gelegentlichem Rauschen in der anderen Richtung
     * bleibt 'normal'.
     */
    private static function RoleShareLevel(float $posShare, float $negShare): string
    {
        if ($posShare < self::ROLE_SHARE_AUFFAELLIG || $negShare < self::ROLE_SHARE_AUFFAELLIG) {
            return 'normal';
        }
        return ($posShare >= self::ROLE_SHARE_KRITISCH && $negShare >= self::ROLE_SHARE_KRITISCH) ? 'kritisch' : 'auffaellig';
    }

    // -----------------------------------------------------------------------
    // Inexogy: nur offizielle Viertelstundenwerte im Zähler-Archiv (0.26.9,
    // Dietmars Auftrag 12.09.2026). Live- und Viertelstundenwerte lagen
    // gemischt im Archiv; der Live-Wert hinkt hinterher, trägt aber den
    // Ankunftszeitpunkt → hunderte kleine Rückschritte, die Symcons
    // Zähler-Verdichtung jeweils doppelt zählt.
    // -----------------------------------------------------------------------

    /** Neuester archivierter Stand eines Energiezählers in kWh (für Treiber), null = keiner. */
    public function LatestArchivedEnergyKWh(string $ident): ?float
    {
        $vid = $this->FindVarByIdent($this->EnergyIdentForInvert($ident));
        $acs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (!$vid || count($acs) === 0 || !AC_GetLoggingStatus($acs[0], $vid)) {
            return null;
        }
        $r = AC_GetLoggedValues($acs[0], $vid, 0, time(), 1);
        if (!$r) {
            return null;
        }
        $v = (float)$r[0]['Value'];
        return $this->ReadPropertyBoolean('EnergyUnitWh') ? $v / 1000.0 : $v;
    }

    /**
     * Welche Archivpunkte sind überholte Live-Zwischenwerte? Offizielle
     * Inexogy-Werte liegen exakt auf dem Viertelstunden-Raster (ts % 900 == 0),
     * Live-Werte zu beliebigen Sekunden. Entfernt wird ein Live-Punkt, wenn
     * offizielle Werte ihn eng umschließen (je höchstens 30 min davor und
     * danach) oder wenn er nach dem letzten offiziellen Wert unter dessen Stand
     * liegt. Live-Punkte in Zeiträumen ohne offizielle Daten bleiben — dort
     * sind sie die einzige Information. Offizielle Punkte werden nie gewählt.
     */
    private static function SelectStaleLivePoints(array $pts): array
    {
        $aligned = [];
        foreach ($pts as [$ts]) {
            if ($ts % 900 === 0) {
                $aligned[] = $ts;
            }
        }
        if (!$aligned) {
            return [];
        }
        $del = [];
        $maxOff = -INF;
        $ai = 0;
        $n = count($aligned);
        foreach ($pts as [$ts, $v]) {
            if ($ts % 900 === 0) {
                $maxOff = max($maxOff, $v);
                continue;
            }
            while ($ai < $n && $aligned[$ai] < $ts) {
                $ai++;
            }
            $prev = $ai > 0 ? $aligned[$ai - 1] : null;
            $next = $ai < $n ? $aligned[$ai] : null;
            $covered = $prev !== null && $next !== null && $ts - $prev <= 1800 && $next - $ts <= 1800;
            if ($covered || ($next === null && $v < $maxOff)) {
                $del[] = $ts;
            }
        }
        return $del;
    }

    /** [doppelt gezählte Energie, Anzahl Rückschritte] einer Zählerreihe — was Symcons Zähler-Verdichtung zu viel zählt. */
    private static function CounterOvercount(array $pts): array
    {
        if (count($pts) < 2) {
            return [0.0, 0];
        }
        $pos = 0.0;
        $back = 0;
        $prev = null;
        foreach ($pts as [, $v]) {
            if ($prev !== null) {
                $d = $v - $prev;
                if ($d > 0) {
                    $pos += $d;
                } elseif ($d < 0) {
                    $back++;
                }
            }
            $prev = $v;
        }
        $net = max(0.0, $pts[count($pts) - 1][1] - $pts[0][1]);
        return [max(0.0, $pos - $net), $back];
    }

    /** Überholte Live-Zwischenwerte finden und (mit $apply) löschen. [Anzahl, Überzählung vorher, nachher, Rückschritte vorher, nachher] */
    private function CleanStaleLivePoints(int $ac, int $vid, int $from, int $to, bool $apply): array
    {
        $pts = $this->LoadArchivePoints($ac, $vid, $from, $to);
        $del = self::SelectStaleLivePoints($pts);
        [$ob, $bb] = self::CounterOvercount($pts);
        $set = array_flip($del);
        $kept = [];
        foreach ($pts as $p) {
            if (!isset($set[$p[0]])) {
                $kept[] = $p;
            }
        }
        [$oa, $ba] = self::CounterOvercount($kept);
        if ($apply && $del) {
            foreach ($del as $ts) {
                AC_DeleteVariableData($ac, $vid, $ts, $ts);
            }
            $this->ReAggregate($ac, $vid);
        }
        return [count($del), $ob, $oa, $bb, $ba];
    }

    public function CheckInexogyArchiveCleanup(int $Days): string
    {
        return $this->InexogyCleanup($Days, false);
    }

    public function CleanInexogyArchive(int $Days): string
    {
        return $this->InexogyCleanup($Days, true);
    }

    private function InexogyCleanup(int $days, bool $apply): string
    {
        if (!in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true)) {
            return MHUB_Text::t('ℹ️ Only for Inexogy meters.');
        }
        $acs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($acs) === 0) {
            return MHUB_Text::t('❌ No archive module (Archive Control) found.');
        }
        $ac = $acs[0];
        $days = max(1, min(45, $days));
        $to = time();
        $from = $to - $days * 86400;
        $kwh = fn(float $x) => number_format($x, 3, ',', '.');
        $lines = [$apply ? MHUB_Text::t('✅ Live intermediate values cleaned up (%d days):', $days) : MHUB_Text::t('🔎 Dry run, nothing changed (%d days):', $days)];
        $total = 0;
        foreach (['energy_import' => MHUB_Text::t('Import'), 'energy_export' => MHUB_Text::t('Feed-in')] as $ident => $label) {
            $vid = $this->FindVarByIdent($ident);
            if (!$vid || !AC_GetLoggingStatus($ac, $vid)) {
                continue;
            }
            [$n, $ob, $oa, $bb, $ba] = $this->CleanStaleLivePoints($ac, $vid, $from, $to, $apply);
            $total += $n;
            $lines[] = $apply
                ? MHUB_Text::t('%s (#%d): %d live intermediate value(s) removed — steps backwards %s → %s, doubly counted energy %s → %s kWh.', $label, $vid, $n, $bb, $ba, $kwh($ob), $kwh($oa))
                : MHUB_Text::t('%s (#%d): %d live intermediate value(s) would be removed — steps backwards %s → %s, doubly counted energy %s → %s kWh.', $label, $vid, $n, $bb, $ba, $kwh($ob), $kwh($oa));
        }
        if (!$apply) {
            $lines[] = $total > 0
                ? MHUB_Text::t('→ "Clean up" does exactly this. Official quarter-hour values stay untouched, as do live values with no official data nearby.')
                : MHUB_Text::t('→ Nothing to clean up.');
        } elseif ($total > 0) {
            $this->LogMessage(str_replace("\n", ' | ', implode("\n", $lines)), KL_MESSAGE);
        }
        return implode("\n", $lines);
    }

    public function GetFunctions(): string
    {
        // Während des Neuladens: leer statt Warnungen (9c). 'ready' ist rein
        // additiv; Konsumenten behalten bei leerer Antwort ihren letzten Stand.
        if (!$this->InstanceReady()) {
            return (string)json_encode(['instanceID' => $this->InstanceID, 'ready' => false, 'assignments' => []]);
        }
        // Zähler-Eigenschaften einmal bestimmen — sie gelten für die ganze
        // Instanz (ein Zähler ist als Ganzes echtzeitnah/träge bzw. abrechnungs-
        // verbindlich, auch im Drei-Phasen-Modus mit drei Zuordnungen).
        $latency      = in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true) ? 'delayed' : 'realtime';
        $authority    = $this->ReadPropertyBoolean('BillingGrade') ? 'billing' : 'auxiliary';
        $pollInterval = $this->ReadPropertyInteger('IntervalFast');
        // Nur für 'delayed' (Cloud-)Zähler sinnvoll — ein lokaler Echtzeit-
        // Zähler archiviert praktisch verzögerungsfrei, dafür extra
        // Archiv-Abfragen zu verursachen brächte keinen Erkenntnisgewinn.
        // Dietmars Wunsch (27.08.2026): der Zeitstempel des letzten
        // vollständig archivierten Datensatzes soll auch Konsumenten wie
        // dem Dashboard zur Verfügung stehen (dessen Strompreis-Diagramm
        // auf denselben archivierten Energiezählern aufbaut und deshalb
        // genau diese Verzögerung erbt) — siehe InexogyArchiveWatermark().
        $archiveWatermarkTs = null;
        if ($latency === 'delayed') {
            [$wm, $wmErr] = $this->InexogyArchiveWatermark();
            $archiveWatermarkTs = ($wmErr === null && $wm > 0) ? $wm : null;
        }

        // Hochgerechnete statt gemessene Energie (MHUB_CalculatedEnergyDriver-
        // Interface, 0.26.4) — Vertrags-Regel „Genauigkeit braucht ein eigenes
        // Flag": ein Konsument soll erkennen können, dass der Zählerstand aus
        // der Leistung gerechnet ist, statt es aus dem Namen zu erraten.
        $driver = $this->GetDriver();
        $calcIdents = $driver instanceof MHUB_CalculatedEnergyDriverInterface ? array_keys($driver->calculatedEnergy()) : [];
        // 1.4: IDs der Energie-Variablen, deren Zählerstand hochgerechnet ist —
        // additiv auf Instanz-Ebene. Grund: MeterHubVirtual bildet Summen aus
        // fremden Variablen und muss erkennen können, ob ein Term hochgerechnet
        // ist (dessen energyMeasured). Die Zuordnungs-Liste reicht dafür nicht,
        // sie kennt nur Zähler MIT Funktion. Die Variable ist die, in die der
        // Setter tatsächlich schreibt (Invers-Umleitung eingerechnet).
        $calculatedEnergyIDs = [];
        foreach ($calcIdents as $ci) {
            $cv = $this->FindVarByIdent($this->EnergyIdentForInvert($ci));
            if ($cv) {
                $calculatedEnergyIDs[$cv] = $cv;
            }
        }
        $calculatedEnergyIDs = array_values($calculatedEnergyIDs);

        $list = [];
        foreach ($this->FunctionAssignments() as $a) {
            $list[] = [
                'slot'            => $a['slot'],
                'function'        => $a['key'],
                'label'           => $a['label'],
                'powerID'         => $this->FindVarByIdent($a['power']),
                'energyImportID'  => $this->FindVarByIdent($a['import']),
                'energyExportID'  => $this->FindVarByIdent($a['export']),
                // Kumulative Zählerstände — auch der hochgerechnete ist einer.
                'energyKind'      => 'counter',
                // 1.3: false = Zählerstand aus der Leistung hochgerechnet
                // (Gerät liefert keinen), true = vom Gerät gemessen.
                'energyMeasured'  => !in_array($a['import'], $calcIdents, true) && !in_array($a['export'], $calcIdents, true),
                // Zähler-Eigenschaften in JEDE Zuordnung gespiegelt (identisch
                // zur Instanz-Ebene). So kann ein Konsument, der über
                // assignments[] iteriert und nach function filtert, authority/
                // latency direkt an der Zuordnung lesen, ohne zum Instanz-Objekt
                // zurückzugreifen. Beide Orte stammen aus derselben Property —
                // sie können nicht auseinanderlaufen.
                'latency'         => $latency,
                'authority'       => $authority,
                'pollInterval'    => $pollInterval,
                'archiveWatermarkTs' => $archiveWatermarkTs,
            ];
        }
        return json_encode([
            // Vertragsversion (Verbund-Konvention): 1.0 = Ur-Vertrag,
            // 1.1 = latency/authority/pollInterval/energyKind-Erweiterung,
            // 1.2 = archiveWatermarkTs (Zeitstempel des letzten vollständig
            // archivierten Datensatzes, nur bei latency='delayed' gesetzt,
            // sonst null). Additiv; Major nur bei Bruch, volle Kompatibilität
            // innerhalb derselben Major. Fehlt das Feld, ist konservativ die
            // jeweils niedrigere Version anzunehmen.
            // 1.3 = energyMeasured je Zuordnung (0.26.4, hochgerechnete
            // Energie beim blue'Log-Datenlogger). Fehlt es, gilt true.
            // 1.4 = calculatedEnergyIDs auf Instanz-Ebene (21.09.2026): die
            // Variablen-IDs mit hochgerechnetem Zählerstand, auch ohne Funktion.
            'contractVersion' => '1.4',
            'instanceID'  => $this->InstanceID,
            'meter'       => $this->ReadPropertyString('Meter'),
            'measureMode' => $this->IsPerPhaseMode() ? 'perphase' : 'combined',
            'latency'     => $latency,
            'authority'   => $authority,
            'pollInterval'=> $pollInterval,
            'archiveWatermarkTs' => $archiveWatermarkTs,
            'calculatedEnergyIDs' => $calculatedEnergyIDs,
            'assignments' => $list,
        ]);
    }

    /**
     * Bekannte Ident-Zuordnung eines Alt-Moduls auf diese MeterHub-Instanz,
     * für MigrationsHubs zentrale Übernahme (Verbund-Vertrag 29.07.2026,
     * gemeinsam mit MigrationsHub/ChargerHub/InverterHub abgestimmt).
     * Reine Auskunft — MeterHub reparentet/benennt selbst nichts um, das
     * bleibt bei MigrationsHub (inkl. Nutzer-Review vor der Ausführung).
     * Rückgabe nur für erkannte Treffer: ['altIdent' => ['ident' =>
     * 'neuerIdent', 'type' => VARIABLETYPE_*], ...]. $foreignIdents muss
     * übergeben werden, weil manche Alt-Module dasselbe Feld je nach
     * Firmware/Version unterschiedlich benennen — ohne die tatsächlich
     * vorhandenen Idents der Alt-Instanz ließe sich das nicht auflösen.
     */
    public function GetIdentMapping(string $foreignModuleGUID, array $foreignIdents): array
    {
        // Alt-Modul-Idents live an Dietmars Installation abgelesen (Modul
        // "Discovergy_Smartmeter" von elueckel, GUID unten) — NICHT zu
        // verwechseln mit Inexogys Cloud-API-JSON-Feldnamen (teils andere
        // Schreibweise, siehe MHUB_InexogyDriver::readFast()); das hier
        // sind die Idents des tatsächlich INSTALLIERTEN Alt-Moduls.
        static $known = [
            '{C0F160B2-0B9D-2AAE-0527-C0FA4BDEE743}' => [
                'energy'    => 'energy_import',
                'energyout' => 'energy_export',
                'power'     => 'power_total',
                'phase1'    => 'p_l1',
                'phase2'    => 'p_l2',
                'phase3'    => 'p_l3',
                'voltage1'  => 'u_l1_n',
                'voltage2'  => 'u_l2_n',
                'voltage3'  => 'u_l3_n',
            ],
        ];
        if (!isset($known[$foreignModuleGUID])) {
            return [];
        }

        // Ziel-Idents dieser Instanz einsammeln (Basis + alle optionalen
        // Gruppen, unabhängig davon, ob die Gruppe aktuell aktiviert ist —
        // RegisterVar() legt die Variable bei Bedarf ohnehin neu an).
        $driver = $this->GetDriver();
        $validTypes = [];
        foreach ($driver->getBaseVars() as $v) {
            $validTypes[$v[0]] = $this->VarTypeFor($v[2]);
        }
        foreach ($driver->getOptionalGroups() as $group) {
            foreach ($group['vars'] as $v) {
                $validTypes[$v[0]] = $this->VarTypeFor($v[2]);
            }
        }

        $out = [];
        foreach ($foreignIdents as $fi) {
            $newIdent = $known[$foreignModuleGUID][$fi] ?? null;
            if ($newIdent === null || !isset($validTypes[$newIdent])) {
                continue;
            }
            $out[$fi] = ['ident' => $newIdent, 'type' => $validTypes[$newIdent]];
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Variablen-Registrierung (generisch, treiberunabhängig)
    // -----------------------------------------------------------------------

    /**
     * Die Variablen, die diese Instanz nach den aktuellen Einstellungen hat: Basis, aktivierte Gruppen, Funktions-
     * Sammelvariablen. Gemeinsame Quelle für das Registrieren und für die Archiv-Prüfung im Formular.
     *
     * @return array ['defs' => Definitionen in Anlegereihenfolge, 'valid' => ident => true, 'mirrors' => Sammelvariablen]
     */
    private function PlannedVariables(): array
    {
        $driver = $this->GetDriver();

        // Funktionszuordnung einmalig auflösen (wird in RegisterVar genutzt).
        $this->funcMap = $this->FunctionByIdent();
        $mirrors = $this->MirrorDefs();

        $valid = [];
        $defs  = [];
        foreach ($driver->getBaseVars() as $v) {
            $valid[$v[0]] = true;
            $defs[] = $v;
        }
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            if ($this->ReadPropertyBoolean($propName)) {
                foreach ($group['vars'] as $v) {
                    $valid[$v[0]] = true;
                    $defs[] = $v;
                }
            }
        }
        // Nur Spiegel anlegen, deren Quellkanal es beim gewählten Zähler gibt
        // (z. B. liefert Phoenix keine Einspeisung, Shelly kein L2 im
        // Dreiphasen-Modus ohne aktivierte Phasengruppe).
        $mirrors = array_values(array_filter($mirrors, function ($d) use ($valid) {
            return isset($valid[$d[7]]);
        }));
        foreach ($mirrors as $d) {
            $valid[$d[0]] = true;
            $defs[] = [$d[0], $d[1], $d[2], $d[3], $d[4], $d[5], ''];
        }
        return ['defs' => $defs, 'valid' => $valid, 'mirrors' => $mirrors];
    }

    private function RegisterVariables()
    {
        $plan    = $this->PlannedVariables();
        $mirrors = $plan['mirrors'];
        $this->PruneForeignObjects($plan['valid']);

        $pos = 0;
        foreach ($plan['defs'] as $def) {
            $this->RegisterVar($def, $pos++);
        }
        // Symbole der Sammel-Variablen je Funktion.
        foreach ($mirrors as $d) {
            $vid = $this->FindVarByIdent($d[0]);
            if ($vid && $d[6] !== '') {
                @IPS_SetIcon($vid, $d[6]);
            }
        }
        $this->WriteAttributeBoolean('OwnCustomProfileCleared', true);
        $this->RemoveLegacyCategories();
        $this->CommitCompactionState();
    }

    /**
     * Räumt die Kategorien (cat_*) ab, die frühere Versionen unter der Instanz angelegt haben
     * (Store-Review 30.10.2026: Kategorien unter Instanzen sind nicht vorgesehen). Läuft nach dem
     * Registrieren: RegisterVar() hat die eigenen Variablen schon direkt unter die Instanz gesetzt,
     * übrig sind leere Kategorien. Eine Kategorie, in der noch etwas anderes liegt (vom Nutzer
     * hineingelegt), bleibt samt Inhalt bestehen — fremde Objekte fasst das Modul nicht an.
     */
    private function RemoveLegacyCategories(): void
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] === 0 && strpos($obj['ObjectIdent'], 'cat_') === 0 && count(IPS_GetChildrenIDs($cid)) === 0) {
                @IPS_DeleteCategory($cid);
            }
        }
    }

    /**
     * Merkt nach einem vollständigen Registrierungsdurchlauf, ob die automatische Verdichtung je Kategorie
     * jetzt angewendet wird. Erst damit löst ein späteres Ausschalten (an → aus) das einmalige Zurücksetzen
     * der Regeln im Archiv aus; ein von vornherein ausgeschalteter Schalter lässt das Archiv in Ruhe.
     */
    private function CommitCompactionState(): void
    {
        foreach (['Power', 'Energy'] as $kind) {
            $on = $this->ReadPropertyBoolean('AutoCompaction' . $kind);
            if ($this->ReadAttributeBoolean('CompactionApplied' . $kind) !== $on) {
                $this->WriteAttributeBoolean('CompactionApplied' . $kind, $on);
            }
        }
    }

    // Entfernt Variablen eines anderen Treibers / deaktivierter Gruppe. Variablen, die
    // noch in einer alten Kategorie (cat_*) liegen, werden dabei mit eingesammelt.
    private function PruneForeignObjects(array $validIdents)
    {
        $all = [];
        $collect = function ($pid) use (&$collect, &$all) {
            foreach (IPS_GetChildrenIDs($pid) as $cid) {
                $all[] = $cid;
                if (IPS_GetObject($cid)['ObjectType'] === 0) {
                    $collect($cid);
                }
            }
        };
        $collect($this->InstanceID);

        foreach ($all as $cid) {
            if (!IPS_ObjectExists($cid)) {
                continue;
            }
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] !== 2 || $obj['ObjectIdent'] === '') {
                continue;
            }
            if (!isset($validIdents[$obj['ObjectIdent']])) {
                $this->RemoveVariable($cid);
            }
        }
    }

    /** Kurzcode (F/I/B/S) aus den Variablendefinitionen -> IPS-Variablentyp. */
    private function VarTypeFor(string $shortType): int
    {
        return [
            'F' => VARIABLETYPE_FLOAT,
            'I' => VARIABLETYPE_INTEGER,
            'B' => VARIABLETYPE_BOOLEAN,
            'S' => VARIABLETYPE_STRING,
        ][$shortType] ?? VARIABLETYPE_FLOAT;
    }

    /**
     * Entfernt eine eigene Variable über $this->UnregisterVariable() (Store-Review Automat, Checkliste 26) statt
     * über IPS_DeleteVariable(). UnregisterVariable() arbeitet über den Ident der Instanz; eine Variable, die noch in
     * einer alten Kategorie liegt, wird dafür zuerst unter die Instanz gesetzt.
     */
    private function RemoveVariable(int $vid): void
    {
        $obj = IPS_GetObject($vid);
        if ($obj['ObjectIdent'] === '') {
            return;
        }
        if ((int)$obj['ParentID'] !== $this->InstanceID) {
            IPS_SetParent($vid, $this->InstanceID);
        }
        $this->UnregisterVariable($obj['ObjectIdent']);
    }

    private function RegisterVar(array $def, int $pos)
    {
        [$ident, $caption, $type, $profile, $archive, $group] = $def;
        // Namen und Registerhinweise stehen im Treiber auf Englisch; einmal je Variable und Übernehmen übersetzen (nicht je Abfrage).
        $caption = MHUB_Text::t($caption);
        $reg = MHUB_Text::t(isset($def[6]) ? $def[6] : '');

        $vtype = $this->VarTypeFor($type);

        $vid = $this->FindVarByIdent($ident);
        if ($vid && IPS_GetVariable($vid)['VariableType'] !== $vtype) {
            $this->RemoveVariable($vid);
            $vid = 0;
        }
        $created = !$vid;
        // Store-Review 30.10.2026: Kategorien unter Instanzen sind nicht vorgesehen — alle Variablen liegen direkt
        // unter der Instanz. Eine Variable, die noch in einer alten Kategorie liegt, wandert hier heraus; die IDs
        // bleiben (Archiv, Verknüpfungen).
        if ($vid && (int)IPS_GetObject($vid)['ParentID'] !== $this->InstanceID) {
            IPS_SetParent($vid, $this->InstanceID);
        }
        $posBefore = $vid ? (int)IPS_GetObject($vid)['ObjectPosition'] : 0;

        // Energie in Wh: statt kWh-Profil das Wh-Profil als Standarddarstellung (×1000 macht SetVarEnergyWh beim
        // Schreiben).
        if ($profile === 'NRG.kWh' && $this->ReadPropertyBoolean('EnergyUnitWh')) {
            $profile = 'MHB.Wh';
        }
        // Store-Review Messwertrechner (Checkliste 28): das benutzerdefinierte Profil gehört dem Benutzer und wird nie
        // gesetzt. Das Profil kommt als Standarddarstellung über RegisterVariable*.
        $presentation = $profile !== '' ? ['PRESENTATION' => VARIABLE_PRESENTATION_LEGACY, 'PROFILE' => $profile] : '';
        switch ($vtype) {
            case VARIABLETYPE_BOOLEAN: $vid = $this->RegisterVariableBoolean($ident, $caption, $presentation, $pos); break;
            case VARIABLETYPE_INTEGER: $vid = $this->RegisterVariableInteger($ident, $caption, $presentation, $pos); break;
            case VARIABLETYPE_STRING:  $vid = $this->RegisterVariableString($ident, $caption, $presentation, $pos); break;
            default:                   $vid = $this->RegisterVariableFloat($ident, $caption, $presentation, $pos);
        }
        // Store-Review-Fund 9m (HeishaMon-Sitzung, 28.09.2026, von InverterHub
        // identisch gefixt): Position nur beim erstmaligen Anlegen setzen, nicht
        // bei jedem ApplyChanges() — sonst wirft eine Umsortierung im Objektbaum
        // von Hand beim nächsten „Übernehmen" die Variable automatisch wieder auf
        // die feste Treiber-Reihenfolge zurück, ohne dass der Nutzer das will.
        if (!$created && (int)IPS_GetObject($vid)['ObjectPosition'] !== $posBefore) {
            IPS_SetPosition($vid, $posBefore);
        }
        // Frühere Versionen haben ein eigenes Profil (NRG.*, MHB.*) als benutzerdefiniertes Profil gesetzt. Das gehört
        // jetzt der Standarddarstellung: ein solches EIGENES Profil wird einmalig entfernt, sonst würde es die Anzeige
        // dauerhaft überdecken (z. B. kWh nach dem Umschalten auf Wh). Ein vom Nutzer gewähltes fremdes Profil bleibt.
        // Nur EINMAL je Instanz (Merker OwnCustomProfileCleared, gesetzt am Ende von RegisterVariables()): trägt der Nutzer
        // später selbst wieder ein Profil ein, bleibt es unangetastet.
        $customProfile = (string)(@IPS_GetVariable($vid)['VariableCustomProfile'] ?? '');
        if (!$this->ReadAttributeBoolean('OwnCustomProfileCleared') && $customProfile !== '' && preg_match('/^(NRG|MHB|MHBV)\./', $customProfile) === 1) {
            IPS_SetVariableCustomProfile($vid, '');
        }

        // Funktionszuordnung: Bezeichnung voranstellen und passendes Icon
        // setzen, damit im Objektbaum direkt „Wärmepumpe — …" steht.
        if (isset($this->funcMap[$ident])) {
            $caption = $this->funcMap[$ident]['label'] . ' — ' . $caption;
            if ($this->funcMap[$ident]['icon'] !== '') {
                @IPS_SetIcon($vid, $this->funcMap[$ident]['icon']);
            }
        }
        IPS_SetName($vid, $caption);

        if ($reg !== '') {
            IPS_SetInfo($vid, $reg);
        }
        if ($archive) {
            // Kumulative Wirkarbeit-Zählerstände (Gruppe "energy") brauchen
            // den Archiv-Aggregationstyp "Zähler" (Delta je Periode), alles
            // andere (Leistung, Spannung, Strom, …) "Standard" (Min/Max/
            // Durchschnitt). Bisher wurde pauschal "Standard" gesetzt — live
            // an Dietmars Anlage verifiziert (echte Wirkarbeit-Zähler dort
            // stehen auf Aggregationstyp 1) und durch Sepps Testerfund am
            // Schwestermodul MeterHubVirtual aufgedeckt (31.08.2026).
            // Intervall für die Verdichtungs-Staffelung: "energy"-Gruppe
            // wird über readSlow() aktualisiert, alles andere über
            // readFast() (siehe die beiden Treiber-Methoden) — daher
            // IntervalSlow bzw. IntervalFast als Update-Frequenz-Signal.
            $interval = $group === 'energy' ? $this->ReadPropertyInteger('IntervalSlow') : $this->ReadPropertyInteger('IntervalFast');
            $this->SetArchive($vid, $group === 'energy', $interval);
        }
    }

    private function FindVarByIdent($ident)
    {
        return $this->FindIdentRecursive($this->InstanceID, $ident);
    }

    private function FindIdentRecursive(int $parentID, $ident)
    {
        foreach (IPS_GetChildrenIDs($parentID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectIdent'] === $ident) {
                return $childID;
            }
            if ($obj['ObjectType'] === 0) {
                $found = $this->FindIdentRecursive($childID, $ident);
                if ($found) {
                    return $found;
                }
            }
        }
        return 0;
    }

    // Sekunden je Verdichtungstyp (0..6) — für den "wäre ein Leerlauf"-
    // Vergleich gegen das Roh-Intervall. Typ 7 (löschen) hat keine
    // Auflösung, wird in CompactionPlan() gesondert behandelt. Identisch
    // zum Schwestermodul MeterHubVirtual — bewusst dupliziert, Module
    // teilen sich keinen Code.
    private const COMPACTION_SECONDS = [0 => 60, 1 => 300, 2 => 3600, 3 => 86400, 4 => 604800, 5 => 2629746, 6 => 31556952];

    /** Auswahlliste für die Verdichtungstyp-Formularfelder (auch von GetConfigurationForm() genutzt). */
    private function CompactionTypeOptions(): array
    {
        return [
            ['caption' => '— off —', 'value' => -1],
            ['caption' => '1× per minute', 'value' => 0],
            ['caption' => '1× per 5 minutes', 'value' => 1],
            ['caption' => '1× per hour', 'value' => 2],
            ['caption' => '1× per day', 'value' => 3],
            ['caption' => '1× per week', 'value' => 4],
            ['caption' => '1× per month', 'value' => 5],
            ['caption' => '1× per year', 'value' => 6],
            ['caption' => 'Delete values', 'value' => 7],
        ];
    }

    /** Sechs Formularfelder für eine Verdichtungs-Kategorie ('Power'/'Energy'), mit erklärender Zwischenüberschrift. */
    private function CompactionFields(string $kind): array
    {
        $opts = $this->CompactionTypeOptions();
        return [
            ['type' => 'CheckBox', 'name' => 'AutoCompaction' . $kind, 'caption' => 'Enable automatic compaction'],
            ['type' => 'Label', 'caption' => '⚠️ When switching off (from on to off), MeterHub actively deletes all three compaction stages of this category in the archive once (including ones set by hand in the console). If the switch is off from the start, MeterHub does not touch the compaction in the archive — new instances therefore start with "off".'],
            [
                'type' => 'PopupButton', 'caption' => 'What do the three compaction stages mean?', 'width' => '480px',
                'popup' => [
                    'caption' => 'Archive compaction in three stages',
                    'items' => [
                        ['type' => 'Label', 'caption' => 'Compaction subsequently reduces the level of detail of logged values so the archive does not grow without limit — the older a value, the coarser it usually can be. Three stages, each with its own point in time and its own target resolution:'],
                        ['type' => 'Label', 'caption' => '"Compact directly to" — applies immediately, from the first logged value.'],
                        ['type' => 'Label', 'caption' => '"After this many months … compact to" (two stages) — only takes effect once a value has reached the given age.'],
                        ['type' => 'Label', 'caption' => 'Example (default presets): directly 1×/minute, after 1 month 1×/5 minutes, after 12 months 1×/hour — values thus automatically become coarser over time, never finer.'],
                        ['type' => 'Label', 'caption' => 'A stage only takes effect if its target resolution is actually COARSER than the polling interval above — otherwise there would be nothing to compact there; the stage then has no effect, even if it is set.'],
                        ['type' => 'Label', 'caption' => '"— off —" on a single stage actively DELETES a rule set there before, exactly like the main switch above — not just "stop touching it".'],
                    ],
                ],
            ],
            ['type' => 'Select', 'name' => 'CompactDirect' . $kind, 'caption' => 'Compact directly to', 'options' => $opts],
            ['type' => 'NumberSpinner', 'name' => 'CompactStage2Months' . $kind, 'caption' => 'After this many months', 'minimum' => 0, 'maximum' => 120, 'suffix' => MHUB_Text::t(' month(s)')],
            ['type' => 'Select', 'name' => 'CompactStage2Type' . $kind, 'caption' => '… compact to', 'options' => $opts],
            ['type' => 'NumberSpinner', 'name' => 'CompactStage3Months' . $kind, 'caption' => 'After this many months', 'minimum' => 0, 'maximum' => 120, 'suffix' => MHUB_Text::t(' month(s)')],
            ['type' => 'Select', 'name' => 'CompactStage3Type' . $kind, 'caption' => '… compact to', 'options' => $opts],
        ];
    }

    /**
     * Verdichtungs-Staffelung aus den Formular-Einstellungen und dem
     * bekannten Update-Intervall — konfigurierbar statt fest im Code
     * (Dietmars Einwand 31.08.2026: „ich bin nicht der einzigste Nutzer,
     * andere haben vielleicht andere Vorstellungen" — seine eigenen Werte
     * bleiben nur noch die VORBELEGUNG der drei Stufen, siehe Create()).
     * Getrennt nach Leistung/Energie (Dietmars Ergänzung, noch am selben
     * Tag: „wir müssen zwischen Leistungswerten und Energiewerten
     * unterscheiden"), daher `$kind` = 'Power' oder 'Energy' als Suffix der
     * gelesenen Properties. Jede Stufe nur mit ihrer gewählten Auflösung,
     * wenn diese tatsächlich GRÖBER ist als das Roh-Intervall — sonst wäre
     * sie ein Leerlauf; Typ 7 (löschen) hat keine Auflösung und wird immer
     * übernommen, wenn gewählt. Intervall bewusst aus der Instanz-eigenen
     * Konfiguration (IntervalFast/IntervalSlow) abgeleitet statt aus der
     * Archiv-Historie geschätzt — die ist bei einer frisch angelegten
     * Instanz noch leer.
     * Rückgabe: IMMER drei [MonatsVersatz, Verdichtungstyp]-Paare für
     * `AC_SetCompaction()` — eine bewusst ausgeschaltete oder rechnerisch
     * überflüssige Stufe bekommt Verdichtungstyp -1 statt zu fehlen (siehe
     * SetArchive()).
     */
    private function CompactionPlan(int $intervalSeconds, string $kind): array
    {
        if (!$this->ReadPropertyBoolean('AutoCompaction' . $kind)) {
            // Nie angewendet (neue Instanz mit Standard „aus" oder ein Bestandswert, der ohne unser
            // Zutun auf aus steht): das Archiv NICHT anfassen. Nur der beobachtete Wechsel von an auf
            // aus setzt zurück (Attribut CompactionApplied, siehe CommitCompactionState()).
            if (!$this->ReadAttributeBoolean('CompactionApplied' . $kind)) {
                return [];
            }
            // Aktiv zurücksetzen statt nur nichts mehr zu tun (Dietmars
            // Entscheidung 01.09.2026, konsistent zur Einzelstufen-Regel
            // oben): alle drei Stufen explizit auf -1, damit auch Regeln aus
            // einem früheren "an"-Zustand verschwinden. Der Hauptschalter
            // im Formular warnt deshalb ausdrücklich davor.
            return [
                [-1, -1],
                [(int)$this->ReadPropertyInteger('CompactStage2Months' . $kind), -1],
                [(int)$this->ReadPropertyInteger('CompactStage3Months' . $kind), -1],
            ];
        }
        $plan = [];
        $stages = [
            [-1, (int)$this->ReadPropertyInteger('CompactDirect' . $kind)],
            [(int)$this->ReadPropertyInteger('CompactStage2Months' . $kind), (int)$this->ReadPropertyInteger('CompactStage2Type' . $kind)],
            [(int)$this->ReadPropertyInteger('CompactStage3Months' . $kind), (int)$this->ReadPropertyInteger('CompactStage3Type' . $kind)],
        ];
        foreach ($stages as [$offset, $type]) {
            // Live verifiziert (Dietmars Fund 01.09.2026: "aus" eingestellt,
            // aber die Monate standen weiter in der Verdichtung): jede Stufe
            // bekommt IMMER einen AC_SetCompaction()-Aufruf, nie einfach
            // übersprungen — Verdichtungstyp -1 löscht die Regel am
            // jeweiligen Monats-Offset aktiv (bestätigt per AC_GetCompaction()
            // vorher/nachher), ein reines "nichts aufrufen" ließ eine schon
            // vorhandene Regel unangetastet stehen. Betrifft nicht nur ein
            // bewusstes "aus", sondern auch den rechnerischen Leerlauf-Fall.
            if ($type !== -1 && ($type === 7 || self::COMPACTION_SECONDS[$type] > $intervalSeconds)) {
                $plan[] = [$offset, $type];
            } else {
                $plan[] = [$offset, -1];
            }
        }
        return $plan;
    }

    // -----------------------------------------------------------------------
    // Archivierung (Store-Review 30.10.2026, Checkliste 19): nur auf Klick im Formular einschalten
    // -----------------------------------------------------------------------

    /** Archiv-Instanz oder 0, wenn es keine gibt. */
    private function ArchiveInstanceID(): int
    {
        if (!function_exists('AC_GetLoggingStatus')) {
            return 0;
        }
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        return count($ids) > 0 ? (int)$ids[0] : 0;
    }

    /**
     * Eigene Variablen, die archiviert werden sollen, aber (noch) nicht geloggt werden.
     *
     * @return array vid => [Zählerstand?, Intervall in Sekunden]
     */
    private function ArchivingMissing(): array
    {
        $ac = $this->ArchiveInstanceID();
        if ($ac <= 0) {
            return [];
        }
        $out = [];
        foreach ($this->PlannedVariables()['defs'] as $def) {
            if (!$def[4]) {
                continue;
            }
            $vid = $this->FindVarByIdent($def[0]);
            if ($vid && !@AC_GetLoggingStatus($ac, $vid)) {
                $out[$vid] = [$def[5] === 'energy', $def[5] === 'energy' ? $this->ReadPropertyInteger('IntervalSlow') : $this->ReadPropertyInteger('IntervalFast')];
            }
        }
        return $out;
    }

    /** Formular-Hinweis samt Knopf; null, wenn alles geloggt wird oder kein Archiv da ist. */
    private function ArchivingPanel(): ?array
    {
        $missing = $this->ArchivingMissing();
        if (!$missing) {
            return null;
        }
        $lines = [];
        foreach (array_keys($missing) as $vid) {
            $lines[] = ['type' => 'Label', 'caption' => sprintf('   • %s (#%d)', IPS_GetName($vid), $vid)];
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PanelLogging', 'expanded' => true,
            'caption' => count($missing) === 1 ? MHUB_Text::t('📈  Archiving missing (1 variable)') : sprintf(MHUB_Text::t('📈  Archiving missing (%d variables)'), count($missing)),
            'items' => array_merge(
                [['type' => 'Label', 'caption' => 'These variables are not archived. Without an archive there is no history, no consumption analysis and no archive compaction. MeterHub does not switch archiving on by itself — that is your decision. The button switches it on for these variables (MeterHub sets the aggregation type and compaction appropriately). Archiving that is already running is never switched off.']],
                $lines,
                [['type' => 'Button', 'caption' => '📈  Enable archiving for these variables', 'onClick' => 'echo MHUB_EnableArchiving($id);']]
            ),
        ];
    }

    /** Schaltet die Archivierung für die fehlenden eigenen Variablen ein (Klick im Formular) und richtet Aggregationstyp und Verdichtung ein. */
    public function EnableArchiving(): string
    {
        $ac = $this->ArchiveInstanceID();
        if ($ac <= 0) {
            return MHUB_Text::t('❌ No archive instance found.');
        }
        $n = 0;
        foreach ($this->ArchivingMissing() as $vid => [$counter, $interval]) {
            AC_SetLoggingStatus($ac, $vid, true);
            $this->SetArchive($vid, $counter, $interval);
            $n++;
        }
        $this->UpdateFormField('PanelLogging', 'visible', false);
        return $n === 0 ? MHUB_Text::t('ℹ️ Nothing was missing — all variables are already archived.') : sprintf(MHUB_Text::t('✅ Archiving enabled for %d variable(s).'), $n);
    }

    private function SetArchive($vid, bool $counter = false, int $intervalSeconds = 5)
    {
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) > 0) {
            // Das Logging einer Variablen gehört dem Benutzer (Store-Review 30.10.2026, Checkliste 19): MeterHub schaltet
            // es nie von selbst ein, sondern nur auf Klick im Formular (EnableArchiving()). Aggregationstyp und
            // Verdichtung setzt es nur dort, wo das Logging schon läuft.
            if (!AC_GetLoggingStatus($archiveIDs[0], $vid)) {
                return;
            }
            AC_SetAggregationType($archiveIDs[0], $vid, $counter ? 1 : 0);
            $plan = $this->CompactionPlan($intervalSeconds, $counter ? 'Energy' : 'Power');
            if ($plan === []) {
                return; // Verdichtung aus und nie angewendet — Archiv unangetastet lassen
            }
            // Verwaiste Regeln an anderen Monats-Offsets zuerst entfernen —
            // siehe MeterHubVirtual::SetArchive() für die volle Herleitung
            // (Sepps zweiter Fund 01.09.2026: "Verdichtungseinträge, die
            // näher an der Gegenwart sind, müssen lockerer als die
            // vorherigen sein"). Live verifiziert.
            $plannedOffsets = array_column($plan, 0);
            $existing = @AC_GetCompaction($archiveIDs[0], $vid);
            if (is_array($existing)) {
                foreach ($existing as $entry) {
                    $offset = (int)($entry['MonthOffset'] ?? 0);
                    if (!in_array($offset, $plannedOffsets, true)) {
                        @AC_SetCompaction($archiveIDs[0], $vid, $offset, -1);
                    }
                }
            }
            foreach ($plan as [$offset, $type]) {
                // @ ist hier bewusst und live geprüft — siehe
                // MeterHubVirtual::SetArchive() für die volle Herleitung
                // (Sepps Fund 01.09.2026: löscht man Typ -1 an einem Monats-
                // Offset ohne vorhandene Regel, wirft AC_SetCompaction() eine
                // PHP-Warnung, die ApplyChanges() als Fehler durchreicht).
                @AC_SetCompaction($archiveIDs[0], $vid, $offset, $type);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Variable setzen (public, damit Treiber sie via $hub->... aufrufen können)
    // -----------------------------------------------------------------------

    public function SetVarFloat(string $ident, float $value)
    {
        if (!is_finite($value)) {
            $value = 0.0;
        }
        // Zentraler Invers-Schalter für die Gesamt-Wirkleistung.
        if ($ident === 'power_total' && $this->ReadPropertyBoolean('PowerInvert')) {
            $value = -$value;
        }
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueFloat($vid, $value);
        }
    }

    // Energie-Setter: der Wert kommt in Wh vom Zähler. Standard-Ausgabe kWh
    // (÷1000); ist „Energie in Wh" aktiv, bleibt der Wh-Wert stehen.
    public function SetVarEnergyWh(string $ident, float $wh)
    {
        if (!is_finite($wh)) {
            $wh = 0.0;
        }
        $vid = $this->FindVarByIdent($this->EnergyIdentForInvert($ident));
        if (!$vid) {
            return;
        }
        $value = $this->ReadPropertyBoolean('EnergyUnitWh') ? $wh : $wh / 1000.0;
        if ($this->AcceptCounter($vid, $value)) {
            SetValueFloat($vid, $value);
        }
    }

    // Energie-Setter für Zähler, die bereits kWh liefern (z. B. Eastron).
    // Standard-Ausgabe kWh; ist „Energie in Wh" aktiv, auf Wh hochrechnen.
    public function SetVarEnergykWh(string $ident, float $kwh)
    {
        if (!is_finite($kwh)) {
            $kwh = 0.0;
        }
        $vid = $this->FindVarByIdent($this->EnergyIdentForInvert($ident));
        if (!$vid) {
            return;
        }
        $value = $this->ReadPropertyBoolean('EnergyUnitWh') ? $kwh * 1000.0 : $kwh;
        if ($this->AcceptCounter($vid, $value)) {
            SetValueFloat($vid, $value);
        }
    }

    /**
     * `PowerInvert` vertauscht bisher nur das Vorzeichen von `power_total`
     * (EIN signierter Wert, Bezug/Einspeisung als +/−). Die Energiezähler
     * sind aber ZWEI getrennte, immer positive Zählerstände (Bezug/Abgabe) —
     * bei vertauschter Anschlussrichtung muss hier nicht das Vorzeichen,
     * sondern das ZIEL vertauscht werden: ein als „Bezug" gelesener Wert
     * landet dann in der Abgabe-Variable und umgekehrt. Live gefunden
     * (Dietmars Inexogy-Instanz, 21.08.2026): `PowerInvert` korrigierte die
     * Leistung, das Dashboard zeigte über die Energiezähler aber weiter
     * Einspeisung statt Bezug an.
     *
     * Betrifft ALLE Treiber mit `energy_import`/`energy_export`-Paaren
     * (inkl. Tarif-/Phasen-Varianten wie `energy_import_t1`,
     * `energy_export_l1`), nicht nur Inexogy — der Fehler lag im
     * gemeinsamen Setter, nicht im Inexogy-Treiber selbst. Nur tauschen,
     * wenn die Gegenrichtung beim aktuellen Zähler überhaupt existiert
     * (z. B. Phoenix EEM hat nur `energy_import`, keine Gegenrichtung) —
     * sonst würde der Wert ins Leere geschrieben und die Variable einfriert.
     */
    private function EnergyIdentForInvert(string $ident): string
    {
        if (!$this->ReadPropertyBoolean('PowerInvert')) {
            return $ident;
        }
        if (str_contains($ident, 'energy_import')) {
            $swapped = str_replace('energy_import', 'energy_export', $ident);
        } elseif (str_contains($ident, 'energy_export')) {
            $swapped = str_replace('energy_export', 'energy_import', $ident);
        } else {
            return $ident;
        }
        return $this->FindVarByIdent($swapped) ? $swapped : $ident;
    }

    public function SetVarInt(string $ident, int $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueInteger($vid, $value);
        }
    }

    public function SetVarBool(string $ident, bool $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueBoolean($vid, $value);
        }
    }

    // -----------------------------------------------------------------------
    // Profile
    // -----------------------------------------------------------------------

    private function CreateProfiles()
    {
        // Gemeinsame NRG-Stack-Profile (Verbund-Konvention 24.07.2026): nur
        // anlegen, wenn sie fehlen — MeterHub ist NICHT Eigentümer und darf
        // Digits/Suffix nicht bei jedem ApplyChanges neu erzwingen, sonst
        // "kämpft" es mit anderen NRG-Stack-Modulen um dieselbe Definition.
        $this->ensureSharedProfile('NRG.Volt',    ' V',   1, 'Electricity');
        $this->ensureSharedProfile('NRG.Ampere',  ' A',   1, 'Electricity');
        $this->ensureSharedProfile('NRG.Watt',    ' W',   0, 'Electricity');
        $this->ensureSharedProfile('NRG.kWh',     ' kWh', 1, 'Electricity');
        $this->ensureSharedProfile('NRG.Percent', ' %',   1, '');

        // Modulspezifische Profile (MeterHub bleibt Eigentümer, Digits/Suffix
        // werden bei jedem ApplyChanges durchgesetzt).
        $this->ensureProfile('MHB.VA',      ' VA',   0, '');
        $this->ensureProfile('MHB.var',     ' var',  0, '');
        $this->ensureProfile('MHB.Hz',      ' Hz',   2, '');
        $this->ensureProfile('MHB.Wh',      ' Wh',   0, 'Electricity');
        $this->ensureProfile('MHB.PF',      '',      2, '');

        // Treiber-spezifische Zusatzprofile (Wert-/Bereichsprofile).
        $driver = $this->GetDriver();
        foreach ($driver->getProfiles() as $name => $def) {
            [$type, $suffix, $min, $max, $step, $digits] = $def;
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, $type);
            }
            IPS_SetVariableProfileDigits($name, $digits);
            IPS_SetVariableProfileText($name, '', $suffix);
            IPS_SetVariableProfileValues($name, $min, $max, $step);
        }

        // Enum-Profile (z. B. Drehfeld beim UMG604).
        foreach ($driver->getEnumProfiles() as $name => $assocs) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
            }
            foreach ($assocs as $value => $def) {
                [$label, $color] = $def;
                IPS_SetVariableProfileAssociation($name, $value, MHUB_Text::t($label), '', $color);
            }
        }
    }

    private function ensureProfile(string $name, string $suffix, int $digits, string $icon)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
        }
        IPS_SetVariableProfileDigits($name, $digits);
        IPS_SetVariableProfileText($name, '', $suffix);
        if ($icon !== '') {
            IPS_SetVariableProfileIcon($name, $icon);
        }
    }

    /**
     * Gemeinsames NRG-Stack-Profil: legt es nur an, wenn es fehlt, und rührt
     * eine bereits vorhandene Definition nicht mehr an. Anders als
     * `ensureProfile()` (modulspezifisch, Digits/Suffix werden durchgesetzt)
     * ist MeterHub hier nicht Eigentümer — ein anderes NRG-Stack-Modul könnte
     * dasselbe Profil bereits mit denselben Vorgaben angelegt haben, und ein
     * fortlaufendes Überschreiben wäre ein stiller Konflikt um die Deutungshoheit.
     */
    private function ensureSharedProfile(string $name, string $suffix, int $digits, string $icon)
    {
        if (IPS_VariableProfileExists($name)) {
            return;
        }
        IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
        IPS_SetVariableProfileDigits($name, $digits);
        IPS_SetVariableProfileText($name, '', $suffix);
        if ($icon !== '') {
            IPS_SetVariableProfileIcon($name, $icon);
        }
    }
}
