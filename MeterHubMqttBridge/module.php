<?php

declare(strict_types=1);

/**
 * MeterHubMqttBridge — Brücke zwischen dem MQTT-Server von Symcon und MeterHub-Instanzen,
 * deren Zähler ihre Daten per MQTT senden (Shelly Gen2+ als „Shelly (MQTT, experimentell)").
 *
 * Warum eine eigene Instanz: Nur sie trägt die MQTT-Schnittstellen in der module.json. Läge das am
 * Hauptmodul, bekäme jede Direkt-Instanz den Hinweis „benötigt eine übergeordnete Instanz" (gleiche
 * Überlegung wie bei MeterHubBridge und bei InverterHubMqttBridge, dessen Datenfluss hier übernommen ist).
 *
 * Datenfluss (am Referenzmodul Schnittcher/IPS-Zigbee2MQTT und an HeishaMon gelesen, nicht an einem
 * Shelly erprobt):
 *   Empfang: ReceiveData($JSONString) mit {"Topic":..., "Payload":...}; ab Kernel-Datum 1670886000 liegt die
 *            Payload UTF-8-kodiert vor (utf8_decode). Der Topic kommt im JSON mit maskierten Schrägstrichen an
 *            (HeishaMon-Erfahrung) — der Empfangsfilter erlaubt deshalb beide Schreibweisen.
 *
 * Die Brücke ist reiner Zwischenspeicher: Sie führt je Geräte-Topic den letzten bekannten Zustand zusammen
 * (Shelly sendet in NotifyStatus nur Änderungen!) und gibt ihn über MHUBM_GetDevice() an die MeterHub-
 * Instanzen weiter, die ihn im eigenen Takt abholen — wie bei jedem anderen Zähler. Sie veröffentlicht nichts
 * und schaltet nichts.
 *
 * Gelesene Topics je Gerät (<Präfix> = die Shelly-MQTT-ID, meist die Geräte-ID):
 *   <Präfix>/events/rpc        NotifyStatus (nur Änderungen) und NotifyFullStatus, standardmäßig an
 *   <Präfix>/status/<Komp.>    vollständiger Zustand einer Komponente (nur wenn „status_ntf" am Gerät an ist)
 *   <Präfix>/online            true/false (Last Will)
 */
class MHUBM_Text
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

class MeterHubMqttBridge extends IPSModule
{
    private const HUB_GUID = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    /** Zählertypen des Hauptmoduls, die über diese Brücke lesen. */
    private const MQTT_METERS = ['shelly_mqtt'];

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        MHUBM_Text::set(function (string $text): string {
            return $this->Translate($text);
        });
    }

    public function Create()
    {
        parent::Create();
        // Welchen MQTT-Server diese Brücke beobachtet (für das Lösen der Anmeldung bei einer Umstellung).
        $this->RegisterAttributeInteger('Parent', 0);
        // Beim Neustart von Symcon läuft ApplyChanges() oft, bevor der MQTT-Server aktiv ist; der Kern meldet danach KR_READY.
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->WatchParent();
        $this->Refresh();
        $this->UpdateStatus();
    }

    /**
     * Der Status der Brücke folgt dem des MQTT-Servers (0.40.3, Forum Dirk 20.11.2026): Bisher wurde er nur in ApplyChanges() gesetzt. Nach einem
     * Symcon-Neustart ist der Server dann oft noch nicht aktiv, die Brücke stand auf „kein aktiver MQTT-Server“ und blieb dort, obwohl längst
     * Nachrichten ankamen. Muster wie ESPHomeHub/RoboHub (IM_CHANGESTATUS des Elternteils, live bestätigt).
     */
    private function WatchParent(): void
    {
        $parent = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $before = $this->ReadAttributeInteger('Parent');
        if ($before > 0 && $before !== $parent) {
            $this->UnregisterMessage($before, IM_CHANGESTATUS);
        }
        $this->WriteAttributeInteger('Parent', $parent);
        if ($parent > 0) {
            $this->RegisterMessage($parent, IM_CHANGESTATUS);
        }
    }

    /** Setzt 102/104 aus dem Zustand des MQTT-Servers; $parentStatus ist der neue Status aus der Meldung (der Kern liefert ihn mit). */
    private function UpdateStatus(?int $parentStatus = null): void
    {
        $s = $this->State();
        $active = $s['connected'] && ($parentStatus ?? $s['parentStatus']) === 102;
        $this->SetStatus($active ? 102 : 104);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Meldungen können noch eintreffen, wenn die Instanz gerade gelöscht wurde.
        if (!IPS_InstanceExists($this->InstanceID)) {
            return;
        }
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->ApplyChanges();
            return;
        }
        if ($Message === IM_CHANGESTATUS && $SenderID === $this->ReadAttributeInteger('Parent')) {
            $this->UpdateStatus((int)($Data[0] ?? 0));
        }
    }

    // -----------------------------------------------------------------------
    // Zuordnung: welche MeterHub-Instanzen zeigen auf diese Brücke
    // -----------------------------------------------------------------------

    /** @return array<int,string> Instanz-ID => Geräte-Topic-Präfix der MeterHub-Instanzen, die auf diese Brücke zeigen. */
    private function LinkedTopics(): array
    {
        $found = [];
        foreach (IPS_GetInstanceListByModuleID(self::HUB_GUID) as $hubId) {
            if (in_array((string)@IPS_GetProperty($hubId, 'Meter'), self::MQTT_METERS, true)
                && (int)@IPS_GetProperty($hubId, 'MqttBridgeID') === $this->InstanceID) {
                $topic = self::CleanTopic((string)@IPS_GetProperty($hubId, 'MqttTopic'));
                if ($topic !== '') {
                    $found[(int)$hubId] = $topic;
                }
            }
        }
        return $found;
    }

    /** Präfix ohne führenden/folgenden Schrägstrich und Leerraum (Wildcards sind nicht erlaubt). */
    private static function CleanTopic(string $topic): string
    {
        $t = trim(trim($topic), '/');
        return (strpbrk($t, '+#') !== false) ? '' : $t;
    }

    /** Baut den Empfangsfilter neu aus den Topics der zugeordneten Instanzen (bei ApplyChanges und vom Hub nach jeder Änderung). */
    public function Refresh(): bool
    {
        $topics = array_values(array_unique($this->LinkedTopics()));
        if (!$topics) {
            $this->SetReceiveDataFilter('(?!)');   // nichts empfangen
            return false;
        }
        $parts = [];
        foreach ($topics as $t) {
            // Schrägstriche im JSON-Datenpaket können maskiert ankommen (\/) — beides zulassen
            $parts[] = implode('\\\\?/', array_map(function ($seg) { return preg_quote($seg, '/'); }, explode('/', $t)));
        }
        $this->SetReceiveDataFilter('.*"Topic":"(' . implode('|', $parts) . ')\\\\?/(events\\\\?/rpc|online|status\\\\?/[^"]*)".*');
        return true;
    }

    // -----------------------------------------------------------------------
    // Empfang
    // -----------------------------------------------------------------------

    public function ReceiveData($JSONString)
    {
        $buffer = json_decode((string)$JSONString, true);
        if (!is_array($buffer) || !isset($buffer['Topic'])) {
            return '';
        }
        $topic = (string)$buffer['Topic'];
        $payload = (string)($buffer['Payload'] ?? '');
        if (IPS_GetKernelDate() > 1670886000) {
            // Symcon liefert die Payload UTF-8-kodiert (die Rohbytes als Latin-1 gelesen) — zurück auf die Rohbytes
            $payload = function_exists('mb_convert_encoding') ? mb_convert_encoding($payload, 'ISO-8859-1', 'UTF-8') : utf8_decode($payload);
        }
        $this->SendDebug('Receive ' . $topic, $payload, 0);
        foreach (array_unique($this->LinkedTopics()) as $prefix) {
            if (strpos($topic, $prefix . '/') === 0) {
                $this->Store($prefix, substr($topic, strlen($prefix) + 1), $payload);
                break;
            }
        }
        return '';
    }

    /** Merkt sich eine Nachricht. */
    private function Store(string $prefix, string $sub, string $payload): void
    {
        $key = 'dev_' . md5($prefix);
        $sem = 'MHUBM_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 2000)) {
            return;
        }
        try {
            $state = json_decode((string)$this->GetBuffer($key), true);
            if (!is_array($state)) {
                $state = ['components' => [], 'online' => null, 'rx' => 0, 'ts' => 0];
            }
            $state['rx'] = time();
            if ($sub === 'online') {
                $state['online'] = strtolower(trim($payload)) === 'true';
            } elseif ($sub === 'events/rpc') {
                $msg = json_decode($payload, true);
                $method = is_array($msg) ? (string)($msg['method'] ?? '') : '';
                $params = is_array($msg) && isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : null;
                if ($params !== null && ($method === 'NotifyStatus' || $method === 'NotifyFullStatus')) {
                    foreach ($params as $comp => $data) {
                        if ($comp === 'ts') {
                            $state['ts'] = (float)$data;
                        } elseif (is_array($data)) {
                            $state['components'][$comp] = self::Merge($state['components'][$comp] ?? [], $data);
                        }
                    }
                    $state['online'] = true;   // ein Gerät, das sendet, ist da
                }
            } elseif (strpos($sub, 'status/') === 0) {
                $comp = substr($sub, 7);
                $data = json_decode($payload, true);
                if ($comp !== '' && is_array($data)) {
                    $state['components'][$comp] = $data;   // vollständiger Zustand ersetzt den Merker
                    $state['online'] = true;
                }
            }
            $this->SetBuffer($key, (string)json_encode($state));
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    /** Änderungen in den bekannten Zustand einmischen: null löscht, Objekte werden rekursiv gemischt, Listen ersetzt. */
    private static function Merge(array $base, array $delta): array
    {
        foreach ($delta as $k => $v) {
            if ($v === null) {
                unset($base[$k]);
            } elseif (is_array($v) && isset($base[$k]) && is_array($base[$k]) && !self::IsList($v) && !self::IsList($base[$k])) {
                $base[$k] = self::Merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    private static function IsList(array $a): bool
    {
        return $a === [] || array_keys($a) === range(0, count($a) - 1);
    }

    // -----------------------------------------------------------------------
    // Abfrage durch MeterHub
    // -----------------------------------------------------------------------

    /**
     * Letzter bekannter Zustand eines Geräts als JSON:
     * {"ok":true,"age":<Sekunden seit der letzten Nachricht>,"online":true|false|null,"components":{"em:0":{...},...}}
     * oder {"ok":false,"error":"not_connected"|"parent_inactive"|"no_topic"|"no_message"}.
     */
    public function GetDevice(string $prefix): string
    {
        $prefix = self::CleanTopic($prefix);
        if ($prefix === '') {
            return (string)json_encode(['ok' => false, 'error' => 'no_topic']);
        }
        $s = $this->State();
        if (!$s['connected']) {
            return (string)json_encode(['ok' => false, 'error' => 'not_connected']);
        }
        if (!$s['parentActive']) {
            return (string)json_encode(['ok' => false, 'error' => 'parent_inactive']);
        }
        $state = json_decode((string)$this->GetBuffer('dev_' . md5($prefix)), true);
        if (!is_array($state) || (int)($state['rx'] ?? 0) === 0) {
            return (string)json_encode(['ok' => false, 'error' => 'no_message']);
        }
        return (string)json_encode([
            'ok'         => true,
            'age'        => max(0, time() - (int)$state['rx']),
            'online'     => $state['online'],
            'components' => $state['components'],
        ]);
    }

    /** @return array{connected: bool, parentActive: bool, parentStatus: int, hubs: int} */
    private function State(): array
    {
        $conn = (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        $connected = $conn > 0 && IPS_InstanceExists($conn);
        $status = $connected ? (int)(IPS_GetInstance($conn)['InstanceStatus'] ?? 0) : 0;
        return ['connected' => $connected, 'parentActive' => $connected && $status === 102, 'parentStatus' => $status, 'hubs' => count($this->LinkedTopics())];
    }

    public function GetState(): string
    {
        return (string)json_encode($this->State());
    }

    public function GetConfigurationForm()
    {
        $s = $this->State();
        if (!$s['connected']) {
            $state = MHUBM_Text::t('⚠️ Not connected to an MQTT server yet — choose the MQTT server of Symcon above under the connection of this instance. Until then this bridge delivers no values.');
        } elseif (!$s['parentActive']) {
            $state = MHUBM_Text::t('⚠️ The MQTT server is not active (status %s) — check the server and its server socket.', $s['parentStatus']);
        } elseif ($s['hubs'] === 0) {
            $state = MHUBM_Text::t('✅ Connected. No MeterHub instance with the meter type "Shelly (MQTT, experimental)" has chosen this bridge yet.');
        } else {
            $state = MHUBM_Text::t('✅ Connected, %d MeterHub instance(s) assigned.', $s['hubs']);
        }
        $rows = [];
        foreach ($this->LinkedTopics() as $hubId => $topic) {
            $d = json_decode($this->GetDevice($topic), true);
            $rows[] = [
                'Instance' => '#' . $hubId . ' ' . @IPS_GetName($hubId),
                'Topic'    => $topic,
                'Last'     => ($d['ok'] ?? false) ? MHUBM_Text::t('%d s ago', (int)$d['age']) : MHUBM_Text::t('no message yet'),
            ];
        }
        return (string)json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => $state],
                ['type' => 'Label', 'caption' => 'This bridge connects the MQTT server of Symcon with MeterHub instances whose meter sends its data via MQTT (Shelly Gen2/Gen3/Pro). It has no settings of its own; the MQTT ID of each device is entered in the MeterHub instance. Several devices can share one bridge.'],
                [
                    'type' => 'List', 'name' => 'Devices', 'caption' => 'Assigned devices', 'rowCount' => 4, 'add' => false, 'delete' => false,
                    'columns' => [
                        ['caption' => 'MeterHub instance', 'name' => 'Instance', 'width' => '200px'],
                        ['caption' => 'MQTT ID of the device', 'name' => 'Topic', 'width' => '260px'],
                        ['caption' => 'Last message', 'name' => 'Last', 'width' => 'auto'],
                    ],
                    'values' => $rows,
                ],
                [
                    'type' => 'ExpansionPanel', 'caption' => '📖  Documentation & help', 'expanded' => false,
                    'items' => [
                        ['type' => 'Label', 'caption' => 'How to set it up: 1) Create the "MQTT Server" of Symcon (with its server socket). 2) In the Shelly web interface open Settings → MQTT, switch MQTT on, enter the IP address of the Symcon computer and the MQTT port, and note the "MQTT prefix" (by default the device ID, e.g. shellypro3em-aabbccddeeff). 3) Create this bridge and connect it to the MQTT server. 4) In the MeterHub instance choose the meter type "Shelly (MQTT, experimental)", select this bridge and enter the MQTT prefix.'],
                        ['type' => 'Label', 'caption' => '🧪 Experimental: derived from the Shelly documentation, not yet tried on a real device. The bridge only listens — it publishes nothing and switches nothing. Shelly sends only changes: until the first message arrives (for a Pro 3EM usually within seconds) the instance shows "no message yet".'],
                    ],
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Connected to the MQTT server.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'No active MQTT server connected.'],
            ],
        ]);
    }
}
