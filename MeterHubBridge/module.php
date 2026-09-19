<?php

declare(strict_types=1);

/**
 * MeterHubBridge — Brücke zwischen einem nativen Symcon-„ModBus Gateway" und
 * MeterHub (Symbox-Gateway-Weg, SUITE.md 9j).
 *
 * Warum eine eigene Instanz: Damit sich eine Instanz in der Konsole an ein
 * Gateway hängen lässt, muss ihre module.json parentRequirements UND
 * implemented tragen (beides an Symcons Referenzmodul symcon/SymconBC EM24-DIN
 * und am ModBus Gateway selbst gelesen). Bei MeterHub selbst hätte das jede
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
class MeterHubBridge extends IPSModule
{
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
     * Reicht einen Modbus-Request (JSON im Format des ModBus Gateways) durch.
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
        if (!$s['connected']) {
            $state = '⚠️ Noch kein Gateway verbunden — oben über „Gateway ändern" das passende native „ModBus Gateway" dieses Geräts wählen.';
        } elseif (!$s['parentActive']) {
            $state = '⚠️ Gateway verbunden, aber nicht aktiv (Status ' . $s['parentStatus'] . ') — Verbindung des Gateways zum Gerät prüfen.';
        } else {
            $state = '✅ Mit dem Gateway verbunden' . ($s['unitId'] !== null ? ' — Unit-ID (DeviceID am Gateway): ' . $s['unitId'] : '') . '.';
        }
        return (string)json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => $state],
                ['type' => 'Label', 'caption' => 'Diese Brücke verbindet ein natives Symcon-„ModBus Gateway" (z. B. den eingebauten RS485-Port der Symbox) mit einer MeterHub-Instanz. Sie hat keine eigenen Einstellungen.'],
                ['type' => 'Label', 'caption' => 'ℹ️ Eine Brücke bedient genau EINE Unit-ID — die „DeviceID" des Gateways. Mehrere Geräte mit verschiedenen Unit-IDs am selben Bus brauchen je ein eigenes Gateway und je eine eigene Brücke.'],
                [
                    'type' => 'ExpansionPanel', 'caption' => '📖  Dokumentation & Hilfe', 'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'So richtest du es ein: 1) Ein natives „ModBus Gateway" für das Gerät anlegen und dessen „DeviceID" auf die Modbus-Adresse des Geräts stellen. 2) Diese Brücke anlegen und über „Gateway ändern" mit dem Gateway verbinden. 3) In der MeterHub-Instanz den Verbindungsweg „Symbox-Gateway" wählen und diese Brücke auswählen.'],
                        ['type' => 'Label', 'caption' => '⚠️ Neuer Verbindungsweg, noch nicht an vielen Geräten erprobt (SUITE.md 9j). Schreibende Zählertypen (blue\'Log RPC/Power Control) nicht darüber betreiben.'],
                    ],
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Mit dem Gateway verbunden.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Kein aktives Gateway verbunden.'],
            ],
        ]);
    }
}
