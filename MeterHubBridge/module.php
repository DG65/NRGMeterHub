<?php

declare(strict_types=1);

/**
 * MeterHubBridge — Brücke zwischen einem nativen Symcon-„ModBus gateway" und
 * MeterHub (Symbox-Gateway-Weg, SUITE.md 9j).
 *
 * Warum eine eigene Instanz: Damit sich eine Instanz in der Konsole an ein
 * Gateway hängen lässt, muss ihre module.json parentRequirements UND
 * implemented tragen (beides an Symcons Referenzmodul symcon/SymconBC EM24-DIN
 * und am ModBus gateway selbst gelesen). Bei MeterHub selbst hätte das jede
 * bestehende Direkt-Instanz mit dem Balken „benötigt eine übergeordnete
 * Instanz" versehen (live beim Schwestermodul InverterHub gesehen). Die Brücke
 * trägt diese Anforderung stattdessen allein — MeterHub bleibt ohne Elternteil,
 * wählt die Brücke in einem Feld und ruft MHUBB_Forward().
 *
 * Eine Brücke bedient genau EINE Unit-ID: die DeviceID ihres Gateways. Die
 * Unit-ID steckt nicht im Request, nur am Gateway.
 *
 * Die Brücke kennt keine Function Codes — sie reicht den Request unverändert
 * durch (Lesen wie Schreiben) und gibt die rohe Antwort base64-kodiert zurück:
 * rohe Registerbytes sind meist kein gültiges UTF-8 und sollen unbeschadet über
 * die Instanzgrenze kommen.
 *
 * ConnectParent() wird bewusst NICHT aufgerufen: es würde ungefragt ein neues
 * Gateway anlegen, obwohl der Nutzer meist schon eines betreibt.
 */
/**
 * Texte zur Laufzeit übersetzen (Quelltexte englisch, Deutsch in locale.json — SUITE.md Punkt 9). Die Modulklasse
 * hinterlegt im Konstruktor ihre Translate()-Methode; auch Hilfsklassen und statische Methoden kommen so an die Übersetzung.
 * Mit Argumenten: sprintf() auf den übersetzten Text.
 */
class MHUBB_Text
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

class MeterHubBridge extends IPSModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        MHUBB_Text::set(function (string $text): string {
            return $this->Translate($text);
        });
    }

    public function Create()
    {
        parent::Create();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $s = $this->State();
        $this->SetStatus($s['connected'] && $s['parentActive'] ? 102 : 104);
    }

    /** @return array{connected: bool, parentActive: bool, parentStatus: int, unitId: ?int} */
    private function State(): array
    {
        $conn = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $connected = $conn > 0 && IPS_InstanceExists($conn);
        $status = $connected ? (int)(IPS_GetInstance($conn)['InstanceStatus'] ?? 0) : 0;
        $unit = null;
        if ($connected) {
            $cfg = json_decode((string)IPS_GetConfiguration($conn), true);
            if (is_array($cfg) && isset($cfg['DeviceID'])) {
                $unit = (int)$cfg['DeviceID'];
            }
        }
        return ['connected' => $connected, 'parentActive' => $connected && $status === 102, 'parentStatus' => $status, 'unitId' => $unit];
    }

    /** Verbindungszustand als JSON — der Hub unterscheidet damit „nicht verbunden" von „Gateway antwortet nicht". */
    public function GetState(): string
    {
        return (string)json_encode($this->State());
    }

    /**
     * Reicht einen Modbus-Request (JSON im Format des ModBus gateways) durch.
     * Antwort immer JSON: {"ok":true,"data":"<base64 der rohen Antwort>"} oder
     * {"ok":false,"error":"not_connected"|"parent_inactive"|"no_response"}.
     */
    public function Forward(string $json): string
    {
        $s = $this->State();
        if (!$s['connected']) {
            return self::Fail('not_connected');
        }
        if (!$s['parentActive']) {
            return self::Fail('parent_inactive');
        }
        try {
            $resp = $this->SendDataToParent($json);
        } catch (\Throwable $e) {
            return self::Fail('no_response');
        }
        if (!is_string($resp) || $resp === '') {
            return self::Fail('no_response');
        }
        return (string)json_encode(['ok' => true, 'data' => base64_encode($resp)]);
    }

    private static function Fail(string $error): string
    {
        return (string)json_encode(['ok' => false, 'error' => $error]);
    }

    public function GetConfigurationForm()
    {
        $s = $this->State();
        $gid = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $gateway = ($gid > 0 && IPS_InstanceExists($gid)) ? '#' . $gid . ' „' . IPS_GetName($gid) . '"' : '';
        if (!$s['connected']) {
            $state = MHUBB_Text::t('⚠️ No gateway connected yet — choose this device\'s matching native "ModBus gateway" above via "Change gateway". Until then this bridge delivers no values to the hub.');
        } elseif (!$s['parentActive']) {
            $state = MHUBB_Text::t('⚠️ Connected to ModBus gateway %s, but it is not active (status %s) — check its connection to the device.', $gateway, $s['parentStatus']);
        } else {
            $state = MHUBB_Text::t('✅ Connected to ModBus gateway %s — unit ID %s (source: property "DeviceID" at the gateway).', $gateway, $s['unitId'] !== null ? (string)$s['unitId'] : MHUBB_Text::t('not readable'));
        }
        return (string)json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => $state],
                ['type' => 'Label', 'caption' => 'This bridge connects a native Symcon "ModBus gateway" (e.g. the built-in RS485 port of the Symbox) with a MeterHub instance. It has no settings of its own.'],
                ['type' => 'Label', 'caption' => 'ℹ️ A bridge serves exactly ONE unit ID — the "DeviceID" of the gateway. Several devices with different unit IDs on the same bus each need a gateway of their own and a bridge of their own.'],
                [
                    'type' => 'ExpansionPanel', 'caption' => '📖  Documentation & help', 'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'How to set it up: 1) Create a native "ModBus gateway" for the device and set its "DeviceID" to the device\'s Modbus address. 2) Create this bridge and connect it to the gateway via "Change gateway". 3) In the MeterHub instance choose the connection path "Symbox gateway" and select this bridge.'],
                        ['type' => 'Label', 'caption' => '⚠️ New connection path, not yet tried on many devices (SUITE.md 9j). Do not operate write-capable meter types (blue\'Log RPC/Power Control) over it.'],
                    ],
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Connected to the gateway.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'No active gateway connected.'],
            ],
        ]);
    }
}
