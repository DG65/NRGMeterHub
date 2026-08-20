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
// Zähler werden nur gelesen (kein writeControl) — daher ist das Interface
// schlanker als beim InverterHub.
// ===========================================================================

class MHUB_ModbusTcpClient
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

    private function modbusRead($fc, $startReg, $count)
    {
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            return null;
        }
        stream_set_timeout($sock, 3);

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', $fc, $startReg, $count);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        fwrite($sock, $mbap . $pdu);

        $response = '';
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 512);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (strlen($response) >= 9) {
                $byteCount = ord($response[8]);
                if (strlen($response) >= 9 + $byteCount) {
                    break;
                }
            }
        }
        fclose($sock);

        if (strlen($response) < 9) {
            return null;
        }

        $rfc = ord($response[7]);
        if ($rfc & 0x80 || $rfc !== $fc) {
            return null;
        }

        $byteCount = ord($response[8]);
        $data      = substr($response, 9, $byteCount);

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
// MHUB_Pac2200Driver — Siemens SENTRON PAC2200
// Float32-Messgrößen ab Register 1, Energiezähler als Double ab Register 801.
// Registeradressen laut Gerätehandbuch L1V30415167A (FC 0x03).
// ---------------------------------------------------------------------------

class MHUB_Pac2200Driver implements MHUB_MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung gesamt',       'F', 'NRG.Watt',   true,  'total',  'FC3 65 (Σ P)'],
            ['voltage_avg',   'Spannung Ø (L-N)',          'F', 'NRG.Volt',   false, 'total',  'FC3 57'],
            ['current_avg',   'Strom Ø',                   'F', 'NRG.Ampere',   false, 'total',  'FC3 61'],
            ['frequency',     'Frequenz',                  'F', 'MHB.Hz',  false, 'total',  'FC3 55'],
            ['energy_import', 'Wirkarbeit Bezug (Tarif 1)','F', 'NRG.kWh', true,  'energy', 'FC3 801 (Wh)'],
            ['energy_export', 'Wirkarbeit Abgabe (Tarif 1)','F','NRG.kWh', true,  'energy', 'FC3 809 (Wh)'],
            ['connected',     'Verbindung',                'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N, L-L)', 'vars' => [
                ['u_l1_n',  'Spannung L1-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 1'],
                ['u_l2_n',  'Spannung L2-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 3'],
                ['u_l3_n',  'Spannung L3-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 5'],
                ['u_l1_l2', 'Spannung L1-L2', 'F', 'NRG.Volt', false, 'voltage', 'FC3 7'],
                ['u_l2_l3', 'Spannung L2-L3', 'F', 'NRG.Volt', false, 'voltage', 'FC3 9'],
                ['u_l3_l1', 'Spannung L3-L1', 'F', 'NRG.Volt', false, 'voltage', 'FC3 11'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase (+ Neutralleiter)', 'vars' => [
                ['i_l1', 'Strom L1',           'F', 'NRG.Ampere', false, 'current', 'FC3 13'],
                ['i_l2', 'Strom L2',           'F', 'NRG.Ampere', false, 'current', 'FC3 15'],
                ['i_l3', 'Strom L3',           'F', 'NRG.Ampere', false, 'current', 'FC3 17'],
                ['i_n',  'Neutralleiterstrom', 'F', 'NRG.Ampere', false, 'current', 'FC3 71'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC3 25'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC3 27'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC3 29'],
            ]],
            'GroupReactiveApparent' => ['caption' => 'Blind-/Scheinleistung (Summe + je Phase)', 'vars' => [
                ['s_total', 'Scheinleistung gesamt', 'F', 'MHB.VA',  false, 'power', 'FC3 63'],
                ['q_total', 'Blindleistung gesamt',  'F', 'MHB.var', false, 'power', 'FC3 67'],
                ['s_l1', 'Scheinleistung L1', 'F', 'MHB.VA',  false, 'power', 'FC3 19'],
                ['s_l2', 'Scheinleistung L2', 'F', 'MHB.VA',  false, 'power', 'FC3 21'],
                ['s_l3', 'Scheinleistung L3', 'F', 'MHB.VA',  false, 'power', 'FC3 23'],
                ['q_l1', 'Blindleistung L1',  'F', 'MHB.var', false, 'power', 'FC3 31'],
                ['q_l2', 'Blindleistung L2',  'F', 'MHB.var', false, 'power', 'FC3 33'],
                ['q_l3', 'Blindleistung L3',  'F', 'MHB.var', false, 'power', 'FC3 35'],
            ]],
            'GroupPowerFactor' => ['caption' => 'Leistungsfaktor', 'vars' => [
                ['pf_total', 'Leistungsfaktor gesamt', 'F', 'MHB.PF', false, 'total', 'FC3 69'],
                ['pf_l1', 'Leistungsfaktor L1', 'F', 'MHB.PF', false, 'total', 'FC3 37'],
                ['pf_l2', 'Leistungsfaktor L2', 'F', 'MHB.PF', false, 'total', 'FC3 39'],
                ['pf_l3', 'Leistungsfaktor L3', 'F', 'MHB.PF', false, 'total', 'FC3 41'],
            ]],
            'GroupTariff2' => ['caption' => 'Energie Tarif 2 (Bezug/Abgabe)', 'vars' => [
                ['energy_import_t2', 'Wirkarbeit Bezug (Tarif 2)',  'F', 'NRG.kWh', true, 'energy', 'FC3 805 (Wh)'],
                ['energy_export_t2', 'Wirkarbeit Abgabe (Tarif 2)', 'F', 'NRG.kWh', true, 'energy', 'FC3 813 (Wh)'],
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
            ['power_total',   'Wirkleistung gesamt',        'F', 'NRG.Watt',   true,  'total',  'FC3 19026 (Psum3)'],
            ['voltage_avg',   'Spannung Ø (L-N)',           'F', 'NRG.Volt',   false, 'total',  'FC3 19000/02/04 Ø'],
            ['current_avg',   'Strom Ø',                    'F', 'NRG.Ampere',   false, 'total',  'FC3 19012/14/16 Ø'],
            ['frequency',     'Frequenz',                   'F', 'MHB.Hz',  false, 'total',  'FC3 19050'],
            ['energy_import', 'Wirkarbeit Bezug',           'F', 'NRG.kWh', true,  'energy', 'FC3 19068 (Wh)'],
            ['energy_export', 'Wirkarbeit Abgabe',          'F', 'NRG.kWh', true,  'energy', 'FC3 19076 (Wh)'],
            ['connected',     'Verbindung',                 'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N, L-L)', 'vars' => [
                ['u_l1_n',  'Spannung L1-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 19000'],
                ['u_l2_n',  'Spannung L2-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 19002'],
                ['u_l3_n',  'Spannung L3-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 19004'],
                ['u_l1_l2', 'Spannung L1-L2', 'F', 'NRG.Volt', false, 'voltage', 'FC3 19006'],
                ['u_l2_l3', 'Spannung L2-L3', 'F', 'NRG.Volt', false, 'voltage', 'FC3 19008'],
                ['u_l3_l1', 'Spannung L3-L1', 'F', 'NRG.Volt', false, 'voltage', 'FC3 19010'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase (+ Summe)', 'vars' => [
                ['i_l1', 'Strom L1',       'F', 'NRG.Ampere', false, 'current', 'FC3 19012'],
                ['i_l2', 'Strom L2',       'F', 'NRG.Ampere', false, 'current', 'FC3 19014'],
                ['i_l3', 'Strom L3',       'F', 'NRG.Ampere', false, 'current', 'FC3 19016'],
                ['i_sum', 'Strom Summe (I1+I2+I3)', 'F', 'NRG.Ampere', false, 'current', 'FC3 19018'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC3 19020'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC3 19022'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC3 19024'],
            ]],
            'GroupReactiveApparent' => ['caption' => 'Blind-/Scheinleistung (Summe + je Phase)', 'vars' => [
                ['s_total', 'Scheinleistung gesamt', 'F', 'MHB.VA',  false, 'power', 'FC3 19034'],
                ['q_total', 'Blindleistung gesamt',  'F', 'MHB.var', false, 'power', 'FC3 19042'],
                ['s_l1', 'Scheinleistung L1', 'F', 'MHB.VA',  false, 'power', 'FC3 19028'],
                ['s_l2', 'Scheinleistung L2', 'F', 'MHB.VA',  false, 'power', 'FC3 19030'],
                ['s_l3', 'Scheinleistung L3', 'F', 'MHB.VA',  false, 'power', 'FC3 19032'],
                ['q_l1', 'Blindleistung L1',  'F', 'MHB.var', false, 'power', 'FC3 19036'],
                ['q_l2', 'Blindleistung L2',  'F', 'MHB.var', false, 'power', 'FC3 19038'],
                ['q_l3', 'Blindleistung L3',  'F', 'MHB.var', false, 'power', 'FC3 19040'],
            ]],
            'GroupPowerFactor' => ['caption' => 'Leistungsfaktor / cos φ', 'vars' => [
                ['pf_total', 'Leistungsfaktor gesamt', 'F', 'MHB.PF', false, 'total', 'FC3 19636'],
                ['pf_l1', 'cos φ L1', 'F', 'MHB.PF', false, 'total', 'FC3 19044'],
                ['pf_l2', 'cos φ L2', 'F', 'MHB.PF', false, 'total', 'FC3 19046'],
                ['pf_l3', 'cos φ L3', 'F', 'MHB.PF', false, 'total', 'FC3 19048'],
            ]],
            'GroupQuality' => ['caption' => 'Netzqualität (THD, Drehfeld)', 'vars' => [
                ['thd_u_l1', 'THD Spannung L1', 'F', 'NRG.Percent', false, 'quality', 'FC3 19110'],
                ['thd_u_l2', 'THD Spannung L2', 'F', 'NRG.Percent', false, 'quality', 'FC3 19112'],
                ['thd_u_l3', 'THD Spannung L3', 'F', 'NRG.Percent', false, 'quality', 'FC3 19114'],
                ['thd_i_l1', 'THD Strom L1',    'F', 'NRG.Percent', false, 'quality', 'FC3 19116'],
                ['thd_i_l2', 'THD Strom L2',    'F', 'NRG.Percent', false, 'quality', 'FC3 19118'],
                ['thd_i_l3', 'THD Strom L3',    'F', 'NRG.Percent', false, 'quality', 'FC3 19120'],
                ['phase_seq', 'Drehfeld', 'I', 'MHB.PhaseSeq', false, 'quality', 'FC3 19052'],
            ]],
        ];
    }

    public function getProfiles() { return []; }

    public function getEnumProfiles()
    {
        return [
            'MHB.PhaseSeq' => [
                -1 => ['Linksdrehfeld', 0xFF8000],
                 0 => ['kein Drehfeld',  0x808080],
                 1 => ['Rechtsdrehfeld', 0x00A000],
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
            ['power_total',   'Wirkleistung gesamt', 'F', 'NRG.Watt',   true,  'total',  'FC3 19030 (Σ P)'],
            ['voltage_avg',   'Spannung Ø (L-N)',    'F', 'NRG.Volt',   false, 'total',  'FC3 19000/02/04 Ø'],
            ['current_avg',   'Strom Ø',             'F', 'NRG.Ampere',   false, 'total',  'FC3 19012/14/16 Ø'],
            ['frequency',     'Frequenz',            'F', 'MHB.Hz',  false, 'total',  'FC3 19054'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'NRG.kWh', true,  'energy', 'FC3 19072 (Wh)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'NRG.kWh', true,  'energy', 'FC3 19080 (Wh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N, L-L)', 'vars' => [
                ['u_l1_n',  'Spannung L1-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 19000'],
                ['u_l2_n',  'Spannung L2-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 19002'],
                ['u_l3_n',  'Spannung L3-N',  'F', 'NRG.Volt', false, 'voltage', 'FC3 19004'],
                ['u_l1_l2', 'Spannung L1-L2', 'F', 'NRG.Volt', false, 'voltage', 'FC3 19006'],
                ['u_l2_l3', 'Spannung L2-L3', 'F', 'NRG.Volt', false, 'voltage', 'FC3 19008'],
                ['u_l3_l1', 'Spannung L3-L1', 'F', 'NRG.Volt', false, 'voltage', 'FC3 19010'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase (+ I4)', 'vars' => [
                ['i_l1',  'Strom L1', 'F', 'NRG.Ampere', false, 'current', 'FC3 19012'],
                ['i_l2',  'Strom L2', 'F', 'NRG.Ampere', false, 'current', 'FC3 19014'],
                ['i_l3',  'Strom L3', 'F', 'NRG.Ampere', false, 'current', 'FC3 19016'],
                ['i_sum', 'Strom I4', 'F', 'NRG.Ampere', false, 'current', 'FC3 19018'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC3 19024'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC3 19026'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC3 19028'],
            ]],
            'GroupReactiveApparent' => ['caption' => 'Blind-/Scheinleistung (Summe + je Phase)', 'vars' => [
                ['s_total', 'Scheinleistung gesamt', 'F', 'MHB.VA',  false, 'power', 'FC3 19038'],
                ['q_total', 'Blindleistung gesamt',  'F', 'MHB.var', false, 'power', 'FC3 19046'],
                ['s_l1', 'Scheinleistung L1', 'F', 'MHB.VA',  false, 'power', 'FC3 19032'],
                ['s_l2', 'Scheinleistung L2', 'F', 'MHB.VA',  false, 'power', 'FC3 19034'],
                ['s_l3', 'Scheinleistung L3', 'F', 'MHB.VA',  false, 'power', 'FC3 19036'],
                ['q_l1', 'Blindleistung L1',  'F', 'MHB.var', false, 'power', 'FC3 19040'],
                ['q_l2', 'Blindleistung L2',  'F', 'MHB.var', false, 'power', 'FC3 19042'],
                ['q_l3', 'Blindleistung L3',  'F', 'MHB.var', false, 'power', 'FC3 19044'],
            ]],
            'GroupPowerFactor' => ['caption' => 'Leistungsfaktor / cos φ', 'vars' => [
                ['pf_l1', 'cos φ L1', 'F', 'MHB.PF', false, 'total', 'FC3 19048'],
                ['pf_l2', 'cos φ L2', 'F', 'MHB.PF', false, 'total', 'FC3 19050'],
                ['pf_l3', 'cos φ L3', 'F', 'MHB.PF', false, 'total', 'FC3 19052'],
            ]],
            'GroupQuality' => ['caption' => 'Netzqualität (THD, Drehfeld)', 'vars' => [
                ['thd_u_l1', 'THD Spannung L1', 'F', 'NRG.Percent', false, 'quality', 'FC3 19114'],
                ['thd_u_l2', 'THD Spannung L2', 'F', 'NRG.Percent', false, 'quality', 'FC3 19116'],
                ['thd_u_l3', 'THD Spannung L3', 'F', 'NRG.Percent', false, 'quality', 'FC3 19118'],
                ['thd_i_l1', 'THD Strom L1',    'F', 'NRG.Percent', false, 'quality', 'FC3 19120'],
                ['thd_i_l2', 'THD Strom L2',    'F', 'NRG.Percent', false, 'quality', 'FC3 19122'],
                ['thd_i_l3', 'THD Strom L3',    'F', 'NRG.Percent', false, 'quality', 'FC3 19124'],
                ['phase_seq', 'Drehfeld', 'I', 'MHB.PhaseSeq', false, 'quality', 'FC3 19056'],
            ]],
        ];
    }

    public function getProfiles() { return []; }

    public function getEnumProfiles()
    {
        return [
            'MHB.PhaseSeq' => [
                -1 => ['Linksdrehfeld', 0xFF8000],
                 0 => ['kein Drehfeld',  0x808080],
                 1 => ['Rechtsdrehfeld', 0x00A000],
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
            ['power_total',   'Wirkleistung gesamt', 'F', 'NRG.Watt',   true,  'total',  'FC4 52'],
            ['voltage_avg',   'Spannung Ø (L-N)',    'F', 'NRG.Volt',   false, 'total',  'FC4 42'],
            ['current_avg',   'Strom Ø',             'F', 'NRG.Ampere',   false, 'total',  'FC4 46'],
            ['frequency',     'Frequenz',            'F', 'MHB.Hz',  false, 'total',  'FC4 70'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'NRG.kWh', true,  'energy', 'FC4 72 (kWh)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'NRG.kWh', true,  'energy', 'FC4 74 (kWh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N, L-L)', 'vars' => [
                ['u_l1_n',  'Spannung L1-N',  'F', 'NRG.Volt', false, 'voltage', 'FC4 0'],
                ['u_l2_n',  'Spannung L2-N',  'F', 'NRG.Volt', false, 'voltage', 'FC4 2'],
                ['u_l3_n',  'Spannung L3-N',  'F', 'NRG.Volt', false, 'voltage', 'FC4 4'],
                ['u_l1_l2', 'Spannung L1-L2', 'F', 'NRG.Volt', false, 'voltage', 'FC4 200'],
                ['u_l2_l3', 'Spannung L2-L3', 'F', 'NRG.Volt', false, 'voltage', 'FC4 202'],
                ['u_l3_l1', 'Spannung L3-L1', 'F', 'NRG.Volt', false, 'voltage', 'FC4 204'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase (+ Neutralleiter)', 'vars' => [
                ['i_l1', 'Strom L1',           'F', 'NRG.Ampere', false, 'current', 'FC4 6'],
                ['i_l2', 'Strom L2',           'F', 'NRG.Ampere', false, 'current', 'FC4 8'],
                ['i_l3', 'Strom L3',           'F', 'NRG.Ampere', false, 'current', 'FC4 10'],
                ['i_n',  'Neutralleiterstrom', 'F', 'NRG.Ampere', false, 'current', 'FC4 224'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC4 12'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC4 14'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC4 16'],
            ]],
            'GroupReactiveApparent' => ['caption' => 'Blind-/Scheinleistung (Summe + je Phase)', 'vars' => [
                ['s_total', 'Scheinleistung gesamt', 'F', 'MHB.VA',  false, 'power', 'FC4 56'],
                ['q_total', 'Blindleistung gesamt',  'F', 'MHB.var', false, 'power', 'FC4 60'],
                ['s_l1', 'Scheinleistung L1', 'F', 'MHB.VA',  false, 'power', 'FC4 18'],
                ['s_l2', 'Scheinleistung L2', 'F', 'MHB.VA',  false, 'power', 'FC4 20'],
                ['s_l3', 'Scheinleistung L3', 'F', 'MHB.VA',  false, 'power', 'FC4 22'],
                ['q_l1', 'Blindleistung L1',  'F', 'MHB.var', false, 'power', 'FC4 24'],
                ['q_l2', 'Blindleistung L2',  'F', 'MHB.var', false, 'power', 'FC4 26'],
                ['q_l3', 'Blindleistung L3',  'F', 'MHB.var', false, 'power', 'FC4 28'],
            ]],
            'GroupPowerFactor' => ['caption' => 'Leistungsfaktor', 'vars' => [
                ['pf_total', 'Leistungsfaktor gesamt', 'F', 'MHB.PF', false, 'total', 'FC4 62'],
                ['pf_l1', 'Leistungsfaktor L1', 'F', 'MHB.PF', false, 'total', 'FC4 30'],
                ['pf_l2', 'Leistungsfaktor L2', 'F', 'MHB.PF', false, 'total', 'FC4 32'],
                ['pf_l3', 'Leistungsfaktor L3', 'F', 'MHB.PF', false, 'total', 'FC4 34'],
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
            ['power_total',   'Wirkleistung gesamt', 'F', 'NRG.Watt',   true,  'total',  'FC4 501−505'],
            ['voltage_avg',   'Spannung Ø',          'F', 'NRG.Volt',   false, 'total',  'FC4 1/3/5 Ø'],
            ['current_avg',   'Strom Ø',             'F', 'NRG.Ampere',   false, 'total',  'FC4 13/15/17 Ø'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'NRG.kWh', true,  'energy', 'FC4 549 (Wh)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'NRG.kWh', true,  'energy', 'FC4 553 (Wh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase', 'vars' => [
                ['u_l1_n', 'Spannung L1', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1'],
                ['u_l2_n', 'Spannung L2', 'F', 'NRG.Volt', false, 'voltage', 'FC4 3'],
                ['u_l3_n', 'Spannung L3', 'F', 'NRG.Volt', false, 'voltage', 'FC4 5'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase', 'vars' => [
                ['i_l1', 'Strom L1', 'F', 'NRG.Ampere', false, 'current', 'FC4 13'],
                ['i_l2', 'Strom L2', 'F', 'NRG.Ampere', false, 'current', 'FC4 15'],
                ['i_l3', 'Strom L3', 'F', 'NRG.Ampere', false, 'current', 'FC4 17'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC4 25'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC4 27'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC4 29'],
            ]],
            'GroupTariff2' => ['caption' => 'Energie nach Tarif (T1/T2)', 'vars' => [
                ['energy_import_t1', 'Bezug Tarif 1',  'F', 'NRG.kWh', true, 'energy', 'FC4 801 (Wh)'],
                ['energy_import_t2', 'Bezug Tarif 2',  'F', 'NRG.kWh', true, 'energy', 'FC4 805 (Wh)'],
                ['energy_export_t1', 'Abgabe Tarif 1', 'F', 'NRG.kWh', true, 'energy', 'FC4 809 (Wh)'],
                ['energy_export_t2', 'Abgabe Tarif 2', 'F', 'NRG.kWh', true, 'energy', 'FC4 813 (Wh)'],
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
            ['power_total',   'Wirkleistung gesamt', 'F', 'NRG.Watt',   true,  'total',  'FC4 4134'],
            ['voltage_avg',   'Spannung Ø (L-N)',    'F', 'NRG.Volt',   false, 'total',  'FC4 4096/98/100 Ø'],
            ['current_avg',   'Strom Ø',             'F', 'NRG.Ampere',   false, 'total',  'FC4 4110/12/14 Ø'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'NRG.kWh', true,  'energy', 'FC4 4358 (Wh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N)', 'vars' => [
                ['u_l1_n', 'Spannung L1-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 4096'],
                ['u_l2_n', 'Spannung L2-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 4098'],
                ['u_l3_n', 'Spannung L3-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 4100'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase', 'vars' => [
                ['i_l1', 'Strom I1', 'F', 'NRG.Ampere', false, 'current', 'FC4 4110'],
                ['i_l2', 'Strom I2', 'F', 'NRG.Ampere', false, 'current', 'FC4 4112'],
                ['i_l3', 'Strom I3', 'F', 'NRG.Ampere', false, 'current', 'FC4 4114'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
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
            ['power_total',   'Wirkleistung gesamt', 'F', 'NRG.Watt',   true,  'total',  'FC4 32790'],
            ['voltage_avg',   'Spannung Ø (L-N)',    'F', 'NRG.Volt',   false, 'total',  'FC4 32774/76/78 Ø'],
            ['current_avg',   'Strom Ø',             'F', 'NRG.Ampere',   false, 'total',  'FC4 32782/84/86 Ø'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'NRG.kWh', true,  'energy', 'FC4 37630 (Wh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N)', 'vars' => [
                ['u_l1_n', 'Spannung L1-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 32774'],
                ['u_l2_n', 'Spannung L2-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 32776'],
                ['u_l3_n', 'Spannung L3-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 32778'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase', 'vars' => [
                ['i_l1', 'Strom I1', 'F', 'NRG.Ampere', false, 'current', 'FC4 32782'],
                ['i_l2', 'Strom I2', 'F', 'NRG.Ampere', false, 'current', 'FC4 32784'],
                ['i_l3', 'Strom I3', 'F', 'NRG.Ampere', false, 'current', 'FC4 32786'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
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
            ['power_total',   'Wirkleistung gesamt', 'F', 'NRG.Watt',   true,  'total',  'FC4 40 (×0,1)'],
            ['voltage_avg',   'Spannung Ø (L-N)',    'F', 'NRG.Volt',   false, 'total',  'FC4 0/2/4 Ø'],
            ['current_avg',   'Strom Ø',             'F', 'NRG.Ampere',   false, 'total',  'FC4 12/14/16 Ø'],
            ['frequency',     'Frequenz',            'F', 'MHB.Hz',  false, 'total',  'FC4 51 (×0,1)'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'NRG.kWh', true,  'energy', 'FC4 52 (×0,1 kWh)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'NRG.kWh', true,  'energy', 'FC4 78 (×0,1 kWh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N)', 'vars' => [
                ['u_l1_n', 'Spannung L1-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 0'],
                ['u_l2_n', 'Spannung L2-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 2'],
                ['u_l3_n', 'Spannung L3-N', 'F', 'NRG.Volt', false, 'voltage', 'FC4 4'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase', 'vars' => [
                ['i_l1', 'Strom L1', 'F', 'NRG.Ampere', false, 'current', 'FC4 12'],
                ['i_l2', 'Strom L2', 'F', 'NRG.Ampere', false, 'current', 'FC4 14'],
                ['i_l3', 'Strom L3', 'F', 'NRG.Ampere', false, 'current', 'FC4 16'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC4 18'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC4 20'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC4 22'],
            ]],
            'GroupReactiveApparent' => ['caption' => 'Blind-/Scheinleistung (Summe + je Phase)', 'vars' => [
                ['s_total', 'Scheinleistung gesamt', 'F', 'MHB.VA',  false, 'power', 'FC4 42'],
                ['q_total', 'Blindleistung gesamt',  'F', 'MHB.var', false, 'power', 'FC4 44'],
                ['s_l1', 'Scheinleistung L1', 'F', 'MHB.VA',  false, 'power', 'FC4 24'],
                ['s_l2', 'Scheinleistung L2', 'F', 'MHB.VA',  false, 'power', 'FC4 26'],
                ['s_l3', 'Scheinleistung L3', 'F', 'MHB.VA',  false, 'power', 'FC4 28'],
                ['q_l1', 'Blindleistung L1',  'F', 'MHB.var', false, 'power', 'FC4 30'],
                ['q_l2', 'Blindleistung L2',  'F', 'MHB.var', false, 'power', 'FC4 32'],
                ['q_l3', 'Blindleistung L3',  'F', 'MHB.var', false, 'power', 'FC4 34'],
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
            ['power_total',   'Wirkleistung gesamt', 'F', 'NRG.Watt',   true,  'total',  'FC3 0xC568 (×10)'],
            ['voltage_avg',   'Spannung Ø (L-N)',    'F', 'NRG.Volt',   false, 'total',  'FC3 0xC558/5A/5C Ø'],
            ['current_avg',   'Strom Ø',             'F', 'NRG.Ampere',   false, 'total',  'FC3 0xC560/62/64 Ø'],
            ['frequency',     'Frequenz',            'F', 'MHB.Hz',  false, 'total',  'FC3 0xC55E'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'NRG.kWh', true,  'energy', 'FC3 0xC702 (×0,01)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'NRG.kWh', true,  'energy', 'FC3 0xC708 (×0,01)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N)', 'vars' => [
                ['u_l1_n', 'Spannung L1-N', 'F', 'NRG.Volt', false, 'voltage', 'FC3 0xC558'],
                ['u_l2_n', 'Spannung L2-N', 'F', 'NRG.Volt', false, 'voltage', 'FC3 0xC55A'],
                ['u_l3_n', 'Spannung L3-N', 'F', 'NRG.Volt', false, 'voltage', 'FC3 0xC55C'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase', 'vars' => [
                ['i_l1', 'Strom L1', 'F', 'NRG.Ampere', false, 'current', 'FC3 0xC560'],
                ['i_l2', 'Strom L2', 'F', 'NRG.Ampere', false, 'current', 'FC3 0xC562'],
                ['i_l3', 'Strom L3', 'F', 'NRG.Ampere', false, 'current', 'FC3 0xC564'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
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
            ['power_total',   'Wirkleistung gesamt', 'F', 'NRG.Watt',   true,  'total',  'FC3 4527'],
            ['voltage_avg',   'Spannung Ø',          'F', 'NRG.Volt',   false, 'total',  'FC3 4567/68/69 Ø'],
            ['frequency',     'Frequenz',            'F', 'MHB.Hz',  false, 'total',  'FC3 4626 (×0,1)'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'NRG.kWh', true,  'energy', 'FC3 4201 (×0,001)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'NRG.kWh', true,  'energy', 'FC3 4281 (×0,001)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase', 'vars' => [
                ['u_l1_n', 'Spannung L1', 'F', 'NRG.Volt', false, 'voltage', 'FC3 4567 (×0,1)'],
                ['u_l2_n', 'Spannung L2', 'F', 'NRG.Volt', false, 'voltage', 'FC3 4568 (×0,1)'],
                ['u_l3_n', 'Spannung L3', 'F', 'NRG.Volt', false, 'voltage', 'FC3 4569 (×0,1)'],
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
            ['power_total',   'Wirkleistung gesamt', 'F', 'NRG.Watt',   true,  'total',  'FC4 1013'],
            ['voltage_avg',   'Spannung Ø',          'F', 'NRG.Volt',   false, 'total',  'FC4 1020/40/60 Ø'],
            ['current_avg',   'Strom Ø',             'F', 'NRG.Ampere',   false, 'total',  'FC4 1022/42/62 Ø'],
            ['frequency',     'Frequenz',            'F', 'MHB.Hz',  false, 'total',  'FC4 1033'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'NRG.kWh', true,  'energy', 'FC4 1162 (Wh)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'NRG.kWh', true,  'energy', 'FC4 1164 (Wh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase', 'vars' => [
                ['u_l1_n', 'Spannung L1', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1020'],
                ['u_l2_n', 'Spannung L2', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1040'],
                ['u_l3_n', 'Spannung L3', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1060'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase', 'vars' => [
                ['i_l1', 'Strom L1', 'F', 'NRG.Ampere', false, 'current', 'FC4 1022'],
                ['i_l2', 'Strom L2', 'F', 'NRG.Ampere', false, 'current', 'FC4 1042'],
                ['i_l3', 'Strom L3', 'F', 'NRG.Ampere', false, 'current', 'FC4 1062'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'NRG.Watt', false, 'power', 'FC4 1024'],
                ['p_l2', 'Wirkleistung L2', 'F', 'NRG.Watt', false, 'power', 'FC4 1044'],
                ['p_l3', 'Wirkleistung L3', 'F', 'NRG.Watt', false, 'power', 'FC4 1064'],
            ]],
            // Eigene Energiezähler je Phase — damit lässt sich jede Phase als
            // eigenständiger Verbraucher führen (Summe der drei = Gesamtzähler).
            'GroupEnergyPhase' => ['caption' => 'Energie je Phase (Bezug/Abgabe)', 'vars' => [
                ['energy_import_l1', 'Wirkarbeit Bezug L1',  'F', 'NRG.kWh', true, 'energy', 'FC4 1182 (Wh)'],
                ['energy_export_l1', 'Wirkarbeit Abgabe L1', 'F', 'NRG.kWh', true, 'energy', 'FC4 1184 (Wh)'],
                ['energy_import_l2', 'Wirkarbeit Bezug L2',  'F', 'NRG.kWh', true, 'energy', 'FC4 1202 (Wh)'],
                ['energy_export_l2', 'Wirkarbeit Abgabe L2', 'F', 'NRG.kWh', true, 'energy', 'FC4 1204 (Wh)'],
                ['energy_import_l3', 'Wirkarbeit Bezug L3',  'F', 'NRG.kWh', true, 'energy', 'FC4 1222 (Wh)'],
                ['energy_export_l3', 'Wirkarbeit Abgabe L3', 'F', 'NRG.kWh', true, 'energy', 'FC4 1224 (Wh)'],
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
            ['power_total',   'Wirkleistung gesamt (Grid)', 'F', 'NRG.Watt',   true,  'total',  'FC4 1080 (Kategorie Grid)'],
            ['voltage_avg',   'Spannung Ø',                 'F', 'NRG.Volt',   false, 'total',  'FC4 1000/1002/1004 Ø'],
            ['current_avg',   'Strom Ø (Grid)',             'F', 'NRG.Ampere',   false, 'total',  'FC4 1072/1074/1076 Ø'],
            ['energy_import', 'Wirkarbeit Bezug',           'F', 'NRG.kWh', true,  'energy', 'FC4 1082 (Grid Ein, F64 Wh)'],
            ['energy_export', 'Wirkarbeit Abgabe',          'F', 'NRG.kWh', true,  'energy', 'FC4 1086 (Grid Aus, F64 Wh)'],
            ['connected',     'Verbindung',                 'B', '~Alert.Reversed', false, 'errors', ''],
        ];
        // Bewusst KEINE Frequenz: Register 1008 ist laut Doku „nicht
        // implementiert" und liefert am echten Gerät NaN.
    }

    public function getOptionalGroups()
    {
        $sensors = [];
        for ($i = 1; $i <= 6; $i++) {
            $b = 1010 + ($i - 1) * 2;
            $sensors[] = ['sens' . $i . '_i',  'Sensor ' . $i . ' Strom',           'F', 'NRG.Ampere',  false, 'current', 'FC4 ' . $b];
            $sensors[] = ['sens' . $i . '_p',  'Sensor ' . $i . ' Leistung',        'F', 'NRG.Watt',  false, 'power',   'FC4 ' . ($b + 12)];
            $sensors[] = ['sens' . $i . '_pf', 'Sensor ' . $i . ' Leistungsfaktor', 'F', 'MHB.PF', false, 'total',   'FC4 ' . ($b + 24)];
        }
        $cats = [];
        foreach ([['home', 'Home', 1046], ['car', 'Car', 1098], ['relais', 'Relais', 1124], ['solar', 'Solar', 1150], ['akku', 'Akku', 1176]] as [$key, $lbl, $base]) {
            $cats[] = [$key . '_power',         $lbl . ' Leistung',    'F', 'NRG.Watt',   false, 'power',  'FC4 ' . ($base + 8)];
            $cats[] = [$key . '_energy_import', $lbl . ' Energie Ein', 'F', 'NRG.kWh', true,  'energy', 'FC4 ' . ($base + 10) . ' (F64 Wh)'];
            $cats[] = [$key . '_energy_export', $lbl . ' Energie Aus', 'F', 'NRG.kWh', true,  'energy', 'FC4 ' . ($base + 14) . ' (F64 Wh)'];
        }
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (inkl. N)', 'vars' => [
                ['u_l1_n', 'Spannung L1', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1000'],
                ['u_l2_n', 'Spannung L2', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1002'],
                ['u_l3_n', 'Spannung L3', 'F', 'NRG.Volt', false, 'voltage', 'FC4 1004'],
                ['u_n',    'Spannung N',  'F', 'NRG.Volt', false, 'voltage', 'FC4 1006'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase (Kategorie Grid, inkl. N)', 'vars' => [
                ['i_l1', 'Strom L1', 'F', 'NRG.Ampere', false, 'current', 'FC4 1072'],
                ['i_l2', 'Strom L2', 'F', 'NRG.Ampere', false, 'current', 'FC4 1074'],
                ['i_l3', 'Strom L3', 'F', 'NRG.Ampere', false, 'current', 'FC4 1076'],
                ['i_n',  'Strom N',  'F', 'NRG.Ampere', false, 'current', 'FC4 1078'],
            ]],
            'GroupGoeSensors'    => ['caption' => 'Stromsensoren 1-6 (Strom/Leistung/Leistungsfaktor)', 'vars' => $sensors],
            'GroupGoeCategories' => ['caption' => 'Kategorien Home/Car/Relais/Solar/Akku (Leistung + Energie)', 'vars' => $cats],
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
        $this->lastError = $err !== ''
            ? 'Netzwerkfehler: ' . $err
            : 'HTTP ' . $code . ($body !== false && $body !== '' ? ' – ' . substr($body, 0, 200) : ' (leere Antwort)');
        return [$code, $body === false ? '' : $body];
    }

    /** Diagnosedetail des zuletzt fehlgeschlagenen http()-Aufrufs. */
    public function getLastError(): string
    {
        return $this->lastError;
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
            ['power_total',   'Wirkleistung gesamt', 'F', 'NRG.Watt',   true,  'total',  'Inexogy values.power /1000'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'NRG.kWh', true,  'energy', 'Inexogy values.energy /1e10'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'NRG.kWh', true,  'energy', 'Inexogy values.energyOut /1e10'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase', 'vars' => [
                ['u_l1_n', 'Spannung L1', 'F', 'NRG.Volt', false, 'voltage', 'Inexogy voltage1 /1000'],
                ['u_l2_n', 'Spannung L2', 'F', 'NRG.Volt', false, 'voltage', 'Inexogy voltage2 /1000'],
                ['u_l3_n', 'Spannung L3', 'F', 'NRG.Volt', false, 'voltage', 'Inexogy voltage3 /1000'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
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
            return false;
        }
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
        if ($imp !== null) { $hub->SetVarEnergykWh('energy_import', $imp / 1e10); }
        if ($exp !== null) { $hub->SetVarEnergykWh('energy_export', $exp / 1e10); }
    }
}

// ---------------------------------------------------------------------------
// MeterHub — Hauptmodul, lädt den Treiber laut Meter-Property
// ---------------------------------------------------------------------------

class MeterHub extends IPSModule
{
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
        'whatwatt'        => 'MHUB_WhatWattDriver',
        'phoenix_eem375'  => 'MHUB_PhoenixEem375Driver',
        'phoenix_eemxm'   => 'MHUB_PhoenixEemXmDriver',
        'carlo_gavazzi_em' => 'MHUB_CarloGavazziDriver',
        'socomec_countis'  => 'MHUB_SocomecCountisDriver',
        'mbs_professional' => 'MHUB_MbsProfessionalDriver',
        'shelly_pro3em'    => 'MHUB_ShellyPro3emDriver',
        'goe_controller'   => 'MHUB_GoeControllerDriver',
        'inexogy'          => 'MHUB_InexogyDriver',
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
        'whatwatt'        => 'WhatWatt',
        'phoenix_eem375'  => 'Phoenix Contact EEM-EM375',
        'phoenix_eemxm'   => 'Phoenix Contact EEM-XM',
        'carlo_gavazzi_em' => 'Carlo Gavazzi EM24 / EM300 / ET340',
        'socomec_countis'  => 'Socomec Countis',
        'mbs_professional' => 'MBS Professional 3-75',
        'shelly_pro3em'    => 'Shelly Pro 3EM',
        'goe_controller'   => 'go-e Controller',
        'inexogy'          => 'Inexogy / Discovergy (Cloud)',
    ];

    // Funktions-Vokabular für die Zuordnung „welcher Verbraucher hängt hier?".
    // [Schlüssel => [Anzeigename, IPS-Icon]]. Bewusst an den Verbraucher-Arten
    // der InverterHubTile-Kachel orientiert, damit andere Module die Zuordnung
    // direkt übernehmen können.
    private const FUNCTIONS = [
        'none'       => ['— keine Zuordnung —',      ''],
        // Anlage / Infrastruktur
        'grid'       => ['Netzanschluss',            'Electricity'],
        'house'      => ['Hausverbrauch',            'HollowHouse'],
        'pv'         => ['PV-Erzeugung',             'Sun'],
        'battery'    => ['Batterie',                 'Battery'],
        // Wärme / Klima
        'heatpump'   => ['Wärmepumpe',               'Temperature'],
        'heater'     => ['Heizung / Heizstab',       'Temperature'],
        'hotwater'   => ['Warmwasser',               'Drops'],
        'aircon'     => ['Klimaanlage',              'Snowflake'],
        'ventilation'=> ['Lüftung',                  'Ventilation'],
        // Mobilität
        'wallbox1'   => ['Wallbox 1',                'Car'],
        'wallbox2'   => ['Wallbox 2',                'Car'],
        'wallbox3'   => ['Wallbox 3',                'Car'],
        'wallbox4'   => ['Wallbox 4',                'Car'],
        'wallbox5'   => ['Wallbox 5',                'Car'],
        'garage'     => ['Garage',                   'Car'],
        // Haushaltsgeräte
        'washer'     => ['Waschmaschine',            'Drops'],
        'dryer'      => ['Trockner',                 'Wind'],
        'dishwasher' => ['Spülmaschine',             'Drops'],
        'oven'       => ['Backofen',                 'Flame'],
        'stove'      => ['Herd',                     'Flame'],
        'fridge'     => ['Kühl-/Gefriergerät',       'Snowflake'],
        'kitchen'    => ['Küche (gesamt)',           'Gear'],
        // Sonstige Bereiche
        'pool'       => ['Pool',                     'Waves'],
        'sauna'      => ['Sauna',                    'Flame'],
        'light'      => ['Beleuchtung',              'Bulb'],
        'it'         => ['Server / Netzwerk',        'Gear'],
        'workshop'   => ['Werkstatt',                'Gear'],
        'other'      => ['Sonstiger Verbraucher',    'Electricity'],
    ];

    private $driver = null;
    /** Ident => Funktionszuordnung, in RegisterVariables() aufgelöst. */
    private $funcMap = [];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
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

        // Inexogy-Cloud-Zugang. E-Mail/Passwort dienen nur dem einmaligen
        // OAuth-Handshake; das Passwort wird danach geleert. Die Tokens liegen
        // in Attributen (nicht im Formular sichtbar, nie im Klartext-Anzeige).
        $this->RegisterPropertyString('InexogyEmail', '');
        $this->RegisterPropertyString('InexogyPassword', '');
        $this->RegisterPropertyString('InexogyMeterID', '');
        // Lastgang-Nachtrag ins Archiv (10.08.2026, Dietmars Abrechnungszähler).
        $this->RegisterPropertyInteger('InexogyBackfillDays', 7);
        $this->RegisterAttributeString('InexogyConsumerKey', '');
        $this->RegisterAttributeString('InexogyConsumerSecret', '');
        $this->RegisterAttributeString('InexogyToken', '');
        $this->RegisterAttributeString('InexogyTokenSecret', '');
        $this->RegisterPropertyInteger('IntervalFast', 5);
        $this->RegisterPropertyInteger('IntervalSlow', 60);

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
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();
        $this->RegisterVariables();

        // Bereitschaft: Modbus-Zähler brauchen eine IP, Cloud-Zähler ein
        // gültiges Zugriffs-Token samt gewählter Zähler-UID.
        $isCloud = in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true);
        $ready   = $isCloud
            ? ($this->ReadAttributeString('InexogyToken') !== '' && $this->ReadPropertyString('InexogyMeterID') !== '')
            : ($this->ReadPropertyString('Host') !== '');
        if (!$this->ReadPropertyBoolean('Active') || !$ready) {
            $this->SetStatus(104);
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('SlowTimer', 0);
            return;
        }

        $this->SetTimerInterval('FastTimer', $this->ReadPropertyInteger('IntervalFast') * 1000);
        $this->SetTimerInterval('SlowTimer', $this->ReadPropertyInteger('IntervalSlow') * 1000);
        $this->SetStatus(102);
    }

    public function ReadFast()
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $ok = $this->GetDriver()->readFast($this->GetTransport(), $this);
        $this->SetStatus($ok ? 102 : 201);
        $this->UpdateMirrors();
    }

    public function ReadSlow()
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $this->GetDriver()->readSlow($this->GetTransport(), $this);
        $this->UpdateMirrors();
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
        $ok = $this->GetDriver()->readFast($this->GetTransport(), $this);
        $this->SetStatus($ok ? 102 : 201);
        $this->GetDriver()->readSlow($this->GetTransport(), $this);
        $this->UpdateMirrors();
        return $ok
            ? '✅ Verbindung erfolgreich, Werte aktualisiert (' . date('H:i:s') . ' Uhr).'
            : '❌ Verbindung fehlgeschlagen — Host/Port/Unit-ID/Zählertyp prüfen.';
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

    /** Variable eines MeterHub-Geräts anhand ihres Idents (liegt in Kategorien). */
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
     * Rückgabe: [instanzID => [kürzel, …]]
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
                        $key = (string)($r['Key'] ?? '?');
                        $out[$iid][$key] = $key;
                        break;
                    }
                }
            }
        }
        return array_map('array_values', $out);
    }

    /** Kürzel aus einem Gerätenamen, eindeutig gegen bereits vergebene. */
    private function VirtualSlug(string $name, array $taken): string
    {
        $s = strtr(mb_strtolower($name, 'UTF-8'), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = trim((string)preg_replace('/[^a-z0-9]+/', '_', $s), '_');
        $s = substr($s !== '' ? $s : 'zaehler', 0, 24);
        $base = $s;
        $i = 2;
        while (isset($taken[$s])) {
            $s = $base . '_' . $i++;
        }
        return $s;
    }

    /**
     * Legt eine MeterHubVirtual-Instanz an und füllt ihre Verdrahtung vor.
     * $partners: Listeninhalt aus der Maske, $role: 'parent' | 'sibling'.
     *
     * Geschrieben wird ausschließlich in die NEUE Instanz — an der eigenen
     * Konfiguration ändert der Knopf nichts.
     */
    public function CreateVirtual($partners = '[]', $role = 'parent')
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
            $say('❌ Es ist kein weiterer Zähler ausgewählt. Ein virtueller Zähler ergibt sich erst aus dem Verhältnis mehrerer Zähler zueinander — bitte oben mindestens eine zweite Instanz eintragen.');
            return 0;
        }
        foreach ($ids as $iid) {
            if (!IPS_InstanceExists($iid)) {
                $say("❌ Instanz #$iid existiert nicht (mehr).");
                return 0;
            }
        }

        // Prüfung: Steckt dieser Zähler schon in einem virtuellen Zähler, wäre
        // ein zweiter der Anfang doppelter Buchführung. Dann lieber dort
        // ergänzen — die Struktur bleibt so eindeutig.
        $member = $this->VirtualMemberships();
        if ($member) {
            $list = [];
            foreach ($member as $iid => $keys) {
                $list[] = '„' . IPS_GetName($iid) . '" (#' . $iid . ', als ' . implode('/', $keys) . ')';
            }
            $say('⚠️ Dieser Zähler ist bereits Teil von ' . implode(', ', $list) . '. Ein zweiter virtueller Zähler mit demselben Gerät führt leicht zu doppelter Buchführung — bitte die Zeile dort ergänzen statt hier neu anzulegen. Es wurde nichts angelegt.');
            return 0;
        }

        // Knoten bauen. Funktionen bleiben bewusst auf „keine Zuordnung":
        // Würde der virtuelle Knoten dieselbe Funktion belegen wie der echte
        // Zähler, erschiene der Verbraucher in Kachel und Sankey doppelt.
        $taken = [];
        $nodes = [];
        $warn  = [];
        $mk = function (int $iid) use (&$taken, &$warn) {
            $key = $this->VirtualSlug(IPS_GetName($iid), $taken);
            $taken[$key] = true;
            $p = $this->MeterVarID($iid, 'power_total');
            $i = $this->MeterVarID($iid, 'energy_import');
            $e = $this->MeterVarID($iid, 'energy_export');
            if ($p === 0 && $i === 0) {
                $warn[] = '„' . IPS_GetName($iid) . '" liefert weder Gesamtleistung noch Bezug — die Zeile bleibt ohne Datenpunkt.';
            }
            if ((string)@IPS_GetProperty($iid, 'MeasureMode') === 'perphase') {
                $warn[] = '„' . IPS_GetName($iid) . '" misst je Phase drei getrennte Verbraucher; übernommen wird die Summe über alle Phasen.';
            }
            return ['Key' => $key, 'Name' => IPS_GetName($iid), 'Parent' => '',
                    'PowerID' => $p, 'EnergyImportID' => $i, 'EnergyExportID' => $e,
                    'Function' => 'none'];
        };

        $ownNode = $mk($this->InstanceID);
        if ($role === 'sibling') {
            // Sammelknoten ohne eigenen Zähler: nur die Summe ist sinnvoll.
            $taken['summe'] = true;
            $nodes[] = ['Key' => 'summe', 'Name' => 'Summe', 'Parent' => '',
                        'PowerID' => 0, 'EnergyImportID' => 0, 'EnergyExportID' => 0,
                        'Function' => 'none'];
            $ownNode['Parent'] = 'summe';
            $nodes[] = $ownNode;
            foreach ($ids as $iid) {
                $n = $mk($iid);
                $n['Parent'] = 'summe';
                $nodes[] = $n;
            }
        } else {
            $nodes[] = $ownNode;
            foreach ($ids as $iid) {
                $n = $mk($iid);
                $n['Parent'] = $ownNode['Key'];
                $nodes[] = $n;
            }
        }

        $iid = IPS_CreateInstance(self::GUID_VIRTUAL);
        IPS_SetName($iid, 'Virtueller Zähler ' . IPS_GetName($this->InstanceID));
        IPS_SetParent($iid, IPS_GetObject($this->InstanceID)['ParentID']);
        IPS_SetProperty($iid, 'Active', true);
        IPS_SetProperty($iid, 'Nodes', json_encode($nodes));
        IPS_ApplyChanges($iid);

        $msg = $role === 'sibling'
            ? "✅ Virtueller Zähler #$iid angelegt: alle " . count($nodes) . " Zähler gleichrangig unter „Summe“ — ausgegeben wird deren Summe."
            : "✅ Virtueller Zähler #$iid angelegt: „" . IPS_GetName($this->InstanceID) . '" als übergeordneter Zähler, ' . count($ids) . ' untergeordnete(r) — ausgegeben werden deren Summe und der Rest (dieser Zähler minus die untergeordneten).';
        $msg .= "\nDie Funktionszuordnung steht dort noch auf „keine“ — bewusst, denn sonst erschiene derselbe Verbraucher in Kachel und Sankey doppelt. Typisch ist, dem übergeordneten Knoten „Hausverbrauch“ zu geben: der Rest ist dann alles, was nicht auf die untergeordneten Zähler entfällt.";
        if ($warn) {
            $msg .= "\n⚠️ " . implode("\n⚠️ ", $warn);
        }
        $say($msg);
        return $iid;
    }

    public function GetConfigurationForm()
    {
        $driver = $this->GetDriver();
        $isCloud = in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true);

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
        $meterOpts = [['caption' => '— bitte zuerst anmelden —', 'value' => '']];
        $curUID = $this->ReadPropertyString('InexogyMeterID');
        if ($curUID !== '') {
            $meterOpts[] = ['caption' => $curUID, 'value' => $curUID];
        }
        $connectionItems = [
            ['type' => 'Label', 'name' => 'InexogyIntro', 'visible' => $isCloud, 'caption' => '🔐 Anmeldung bei Inexogy (ehem. Discovergy). E-Mail und Passwort deines my.inexogy.com-Kontos eintragen, übernehmen, dann „Anmelden". Das Passwort wird nur einmal für die Anmeldung benutzt, danach automatisch gelöscht — gespeichert werden ausschließlich Zugriffs-Token (nicht im Klartext).'],
            ['type' => 'ValidationTextBox', 'name' => 'InexogyEmail', 'visible' => $isCloud, 'caption' => 'E-Mail (Inexogy-Konto)'],
            ['type' => 'PasswordTextBox', 'name' => 'InexogyPassword', 'visible' => $isCloud, 'caption' => 'Passwort (wird nach der Anmeldung gelöscht)'],
            ['type' => 'Button', 'name' => 'InexogyLoginButton', 'visible' => $isCloud, 'caption' => '🔑  Anmelden und Zähler abrufen', 'onClick' => 'MHUB_InexogyLogin($id);'],
            ['type' => 'Label', 'name' => 'InexogyResult', 'caption' => '', 'visible' => false],
            ['type' => 'Select', 'name' => 'InexogyMeterID', 'visible' => $isCloud, 'caption' => 'Zähler-UID', 'options' => $meterOpts],
            ['type' => 'Label', 'name' => 'InexogyHintPoll', 'visible' => $isCloud, 'caption' => 'ℹ️ Cloud-Zähler: sinnvoller Abfragetakt 60 s oder mehr (unten im Panel „Abfragetakt"). Als abrechnungsverbindlich empfiehlt sich die Checkbox oben, damit ein EMS ihn vom Echtzeit-Zähler unterscheidet.'],
            ['type' => 'Label', 'name' => 'InexogyHintMigration', 'visible' => $isCloud, 'caption' => '→ Umstieg von einem anderen Discovergy-/Inexogy-Modul mit Übernahme der Messhistorie? Diese Instanz erst mit „Kommunikation aktiv = AUS" anlegen und anmelden, dann mit MigrationsHub adoptieren, danach „Kommunikation aktiv = AN". So bleibt die Zielvariable bis zur Übernahme ohne eigene Historie.'],
            ['type' => 'Label', 'name' => 'InexogyHintBackfill', 'visible' => $isCloud, 'caption' => '📊 Lastgang (15-Minuten-Werte) nachtragen: füllt das Archiv von „Wirkarbeit Bezug/Abgabe" (und „Wirkleistung gesamt") rückwirkend mit den echten Zählerständen aus dem Inexogy-Lastgang — genauer als der laufende Abfragetakt, z. B. zur Kontrolle einer Abrechnung. Auch für größere Rückstände (mehrere Monate) geeignet — läuft dann intern in Blöcken, kann etwas dauern. Erneutes Klicken (z. B. wöchentlich) trägt nur die inzwischen neu hinzugekommenen Werte nach.'],
            ['type' => 'NumberSpinner', 'name' => 'InexogyBackfillDays', 'visible' => $isCloud, 'caption' => 'Lastgang der letzten … Tage nachtragen', 'minimum' => 1, 'maximum' => 730],
            ['type' => 'Button', 'name' => 'InexogyBackfillButton', 'visible' => $isCloud, 'caption' => '📊  Lastgang jetzt ins Archiv nachtragen', 'onClick' => 'MHUB_BackfillInexogyArchive($id);'],
            ['type' => 'Label', 'name' => 'InexogyBackfillResult', 'caption' => '', 'visible' => false],
            ['type' => 'ValidationTextBox', 'name' => 'Host', 'visible' => !$isCloud, 'caption' => 'IP-Adresse', 'validate' => $isCloud ? '' : '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
            ['type' => 'NumberSpinner', 'name' => 'Port', 'visible' => !$isCloud, 'caption' => 'TCP-Port', 'minimum' => 1, 'maximum' => 65535],
            ['type' => 'NumberSpinner', 'name' => 'UnitId', 'visible' => !$isCloud, 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
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
            ['type' => 'Label', 'caption' => 'Zuerst festlegen, WIE dieser Zähler misst — daraus ergibt sich, ob eine Funktion für das ganze Gerät oder je Phase eine eigene zugeordnet wird.'],
            [
                'type'    => 'Select',
                'name'    => 'MeasureMode',
                'caption' => 'Messmodus',
                'options' => [
                    ['caption' => 'Dreiphasig — ein Verbraucher über alle 3 Phasen (z. B. Netzanschluss, Wärmepumpe)', 'value' => 'combined'],
                    ['caption' => 'Einphasig getrennt — 3 unabhängige Verbraucher (je Phase einer)',                    'value' => 'perphase'],
                ],
            ],
            ['type' => 'Label', 'caption' => 'Nach dem Umschalten einmal „Übernehmen" — danach erscheinen hier die passenden Zuordnungsfelder.'],
        ];
        if ($this->IsPerPhaseMode()) {
            $funcItems[] = ['type' => 'Label', 'caption' => '⚡ Je Phase einen Verbraucher zuordnen. Für getrennte Energiezähler zusätzlich im Panel „Datenpunkte" die Gruppe „Energie je Phase" aktivieren (sofern der Zähler sie unterstützt).'];
            foreach (['L1', 'L2', 'L3'] as $ph) {
                $funcItems[] = ['type' => 'Select', 'name' => 'Func' . $ph, 'caption' => 'Phase ' . $ph . ' — Funktion', 'options' => $funcOptions];
                $funcItems[] = ['type' => 'ValidationTextBox', 'name' => 'FuncLabel' . $ph, 'caption' => 'Phase ' . $ph . ' — eigene Bezeichnung (optional, z. B. „Garage hinten")'];
            }
        } else {
            $funcItems[] = ['type' => 'Select', 'name' => 'FuncTotal', 'caption' => 'Funktion dieses Zählers', 'options' => $funcOptions];
            $funcItems[] = ['type' => 'ValidationTextBox', 'name' => 'FuncTotalLabel', 'caption' => 'Eigene Bezeichnung (optional, ersetzt den Namen der Funktion)'];
        }
        $funcItems[] = ['type' => 'CheckBox', 'name' => 'FuncMirrors', 'caption' => 'Zusätzliche Sammel-Variablen je Funktion anlegen (Leistung/Bezug/Einspeisung unter „Funktionen")'];
        $funcItems[] = ['type' => 'Label', 'caption' => 'Die Zuordnung benennt die betroffenen Variablen um (z. B. „Wärmepumpe — Wirkarbeit Bezug") und setzt ein passendes Icon. Andere Module (EMS, Kacheln) können die Zuordnung per MHUB_GetFunctions(' . $this->InstanceID . ') auslesen.'];

        // --- Virtueller Zähler: Hinweis und Generator ------------------------
        $meterOptions = [['caption' => '— bitte wählen —', 'value' => 0]];
        foreach (IPS_GetInstanceListByModuleID(self::GUID_METER) as $iid) {
            if ($iid !== $this->InstanceID) {
                $meterOptions[] = ['caption' => IPS_GetName($iid) . '  (#' . $iid . ')', 'value' => $iid];
            }
        }
        $virtualItems = [
            ['type' => 'Label', 'caption' => 'Ein virtueller Zähler rechnet mehrere echte Zähler zusammen — typischerweise „Hauptzähler minus Unterzähler = Rest". Beschrieben wird dabei nicht eine Formel, sondern die Verdrahtung: welcher Zähler hinter welchem sitzt. Weil jeder Zähler darin genau einen Platz hat, kann er nicht versehentlich doppelt abgezogen werden.'],
        ];
        $member = $this->VirtualMemberships();
        if ($member) {
            foreach ($member as $iid => $keys) {
                $virtualItems[] = ['type' => 'Label', 'caption' => '🔗 Dieser Zähler ist eingebunden in „' . IPS_GetName($iid) . '" (#' . $iid . ') als „' . implode('", „', $keys) . '".'];
            }
            $virtualItems[] = ['type' => 'Label', 'caption' => 'Weitere Zähler werden am besten dort ergänzt — ein zweiter virtueller Zähler mit demselben Gerät führt leicht zu doppelter Buchführung.'];
        } else {
            $virtualItems[] = ['type' => 'Label', 'caption' => 'Dieser Zähler ist bisher in keinem virtuellen Zähler eingebunden. Hier lässt sich direkt einer anlegen: die weiteren beteiligten Zähler auswählen, Rolle festlegen, Knopf drücken. Angelegt wird eine eigene Instanz „MeterHubVirtual" — an dieser Konfiguration ändert sich nichts.'];
            $virtualItems[] = [
                'type' => 'Select', 'name' => 'VirtualRole', 'caption' => 'Rolle dieses Zählers',
                'options' => [
                    ['caption' => 'Übergeordnet — die gewählten Zähler hängen dahinter (ergibt Summe + Rest)', 'value' => 'parent'],
                    ['caption' => 'Gleichrangig — alle Zähler werden nur addiert (ergibt Summe)',              'value' => 'sibling'],
                ],
            ];
            $virtualItems[] = [
                'type' => 'List', 'name' => 'VirtualPartners', 'caption' => 'Weitere beteiligte Zähler',
                'rowCount' => 4, 'add' => true, 'delete' => true,
                'columns' => [
                    ['caption' => 'MeterHub-Instanz', 'name' => 'InstanceID', 'width' => 'auto', 'add' => 0,
                     'edit' => ['type' => 'Select', 'options' => $meterOptions]],
                ],
            ];
            $virtualItems[] = ['type' => 'Button', 'caption' => '🧮  Virtuellen Zähler anlegen', 'onClick' => 'MHUB_CreateVirtual($id, $VirtualPartners, $VirtualRole);'];
            $virtualItems[] = ['type' => 'Label', 'name' => 'VirtualResult', 'caption' => '', 'visible' => false];
            $virtualItems[] = ['type' => 'Label', 'caption' => 'Die neue Instanz kann anschließend beliebig erweitert werden — auch um Zähler, die gar nicht von MeterHub kommen (Steckdosen, Licht- und Jalousieschalter). Dafür hat sie einen eigenen Suchlauf.'];
        }

        $form = [
            'elements' => [
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖  Dokumentation & Hilfe',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'MeterHub liest Energiezähler verschiedener Hersteller direkt per Modbus TCP aus. Zählertyp wählen, IP-Adresse (und ggf. Port/Unit-ID) eintragen, Datenpunkt-Gruppen je nach Bedarf aktivieren.'],
                        ['type' => 'Label', 'caption' => '🔀 Umstieg von einem anderen Zähler-/Hub-Modul mit Übernahme der Messhistorie geplant? Diese Instanz erst mit „Kommunikation aktiv = AUS" anlegen und konfigurieren, dann mit MigrationsHub die alte Historie übernehmen, danach „Kommunikation aktiv = AN". So bleibt die Zielvariable bis zur Übernahme ohne eigene, sich mit der Alt-Historie überlappende Werte.'],
                        ['type' => 'Label', 'caption' => 'Unterstützte Zähler: Siemens SENTRON PAC2200 (FC 0x03); Janitza-UMG-Reihe (UMG 604/605/509/512/806/96PA/801 klassische Karte, UMG 800 Werkskarte, FC 0x03); Eastron SDM72D-M v2, WhatWatt und Phoenix Contact EEM-EM375/EEM-XM (FC 0x04, Input-Register).'],
                        ['type' => 'Label', 'caption' => 'Hinweis Eastron/Phoenix: Diese sprechen meist Modbus RTU und hängen über einen RTU/TCP-Gateway (dessen IP eintragen). Eastron-Geräteadresse ab Werk 1; Phoenix EEM-EM375 nutzt oft Unit-ID 255, EEM-XM meist 1. WhatWatt spricht Modbus TCP direkt.'],
                        ['type' => 'Label', 'caption' => '🧪 Experimentell: Socomec Countis und MBS Professional 3-75 sind aus Vorlagen abgeleitet und noch nicht an echter Hardware geprüft — bitte die Messwerte gegen die Geräteanzeige abgleichen. Bei unplausiblen Werten helfen der WordSwap- bzw. Invers-Schalter.'],
                        ['type' => 'Label', 'caption' => '🔌 Shelly Pro 3EM: Modbus TCP muss am Gerät erst aktiviert werden (Einstellungen → Modbus, Port 502). Gelesen über FC 0x04, Float wortgetauscht (CDAB); Wire-Adressen = Doku − 30000 (Messwerte ab 1011, Energie 1162/1164). An echtem Gerät verifiziert.'],
                        ['type' => 'Label', 'caption' => '🔌 go-e Controller: Modbus TCP muss am Gerät erst aktiviert werden (go-e-App: Internet → Erweiterte Einstellungen → Modbus, oder HTTP-API men=true) — sonst bleibt Port 502 geschlossen; nach dem Aktivieren die Einstellung ggf. einmal aus-/einschalten. Kernwerte kommen aus der Kategorie Grid; Sensoren 1-6 und die Kategorien Home/Car/Relais/Solar/Akku sind zuschaltbar. An echtem Gerät verifiziert. (Die go-e-Wallboxen selbst bedient das Modul ChargerHub.)'],
                        ['type' => 'Label', 'caption' => '⚠️ go-e Controller + Überschussladen: Der Controller kann die go-e-Wallboxen SELBST regeln (PV-Überschussladen, Lastbegrenzung — geräteinterne Regelschleife). Soll stattdessen ein EMS die Wallboxen steuern, muss diese interne Regelung an den Wallboxen deaktiviert sein — sonst arbeiten zwei Regler gegeneinander. Dieses Modul liest nur und ist davon nicht betroffen; der Regelzustand ist per Modbus nicht sichtbar, sondern nur an den Wallboxen selbst (go-e-API: usePvSurplus, Lastmanagement, modelStatus).'],
                        ['type' => 'Label', 'caption' => 'ℹ️ Vorzeichen-Konvention: + = Bezug aus dem Netz, − = Einspeisung. Stimmt die Richtung an der eigenen Anlage nicht, hilft der Invers-Schalter unten.'],
                        ['type' => 'Label', 'caption' => '🔧 Anschluss: Die Zähler nutzen Modbus-TCP-Port 502. Die Unit-/Geräteadresse ist ab Werk meist 1 (der PAC2200 antwortet oft auch unabhängig von der Unit-ID).'],
                        ['type' => 'Label', 'caption' => '⚠️ UMG 800: Dessen Modbus-Zuordnung ist frei konfigurierbar — dieser Treiber folgt der ausgelieferten Werksvorgabe. Wurde sie im Gerät (GridVis) geändert, stimmen die Adressen ggf. nicht.'],
                        ['type' => 'Label', 'caption' => 'Registeradressen stehen im Beschreibungsfeld jeder Variable (Objekt-Manager, Spalte „Beschreibung").'],
                    ],
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'Active',
                    'caption' => 'Kommunikation aktiv',
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
                ['type' => 'Label', 'caption' => '👉 Zuerst den tatsächlichen Zählertyp wählen — die Vorauswahl unten ist nur ein technischer Platzhalter, keine Empfehlung. Kein eigener Modbus-Zähler? „Inexogy / Discovergy" nutzt stattdessen die Cloud-API. Die Verbindungsfelder darunter passen sich automatisch an die Auswahl an.'],
                [
                    'type'     => 'Select',
                    'name'     => 'Meter',
                    'caption'  => 'Zählertyp',
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
                        ['caption' => 'Janitza UMG 800 (konfigurierbare Map — Werksvorgabe)', 'value' => 'janitza_umg800'],
                        ['caption' => 'Eastron SDM72D-M v2',     'value' => 'eastron_sdm72d'],
                        ['caption' => 'Eastron SDM630 v2',       'value' => 'eastron_sdm630'],
                        ['caption' => 'WhatWatt',                'value' => 'whatwatt'],
                        ['caption' => 'Phoenix Contact EEM-EM375', 'value' => 'phoenix_eem375'],
                        ['caption' => 'Phoenix Contact EEM-XM',  'value' => 'phoenix_eemxm'],
                        ['caption' => 'Carlo Gavazzi EM24 / EM300 / ET340', 'value' => 'carlo_gavazzi_em'],
                        ['caption' => 'Socomec Countis (experimentell)', 'value' => 'socomec_countis'],
                        ['caption' => 'MBS Professional 3-75 (experimentell)', 'value' => 'mbs_professional'],
                        ['caption' => 'Shelly Pro 3EM', 'value' => 'shelly_pro3em'],
                        ['caption' => 'go-e Controller', 'value' => 'goe_controller'],
                        ['caption' => 'Inexogy / Discovergy (Cloud-API, kein Modbus)', 'value' => 'inexogy'],
                    ],
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'Role',
                    'caption' => 'Rolle des Zählers',
                    'options' => [
                        ['caption' => 'Netz-/NAP-Zähler (+ Bezug / − Einspeisung)', 'value' => 'grid'],
                        ['caption' => 'Unterzähler / Verbraucher (immer positiv)', 'value' => 'consumption'],
                    ],
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'BillingGrade',
                    'caption' => 'Abrechnungsverbindlicher Zähler am Netzübergabepunkt (geeichtes iMSys/mMSD) — der Wert, der auf der Stromrechnung steht',
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🏷️  Funktionszuordnung',
                    'expanded' => false,
                    'items'    => $funcItems,
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🧮  Virtueller Zähler (Summe / Rest aus mehreren Zählern)',
                    'expanded' => false,
                    'items'    => $virtualItems,
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => $isCloud ? '🔐  Cloud-Zugang' : '🔌  Verbindung',
                    'expanded' => true,
                    'items' => $connectionItems,
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '⏱️  Polling',
                    'expanded' => false,
                    'items' => [
                        ['type' => 'NumberSpinner', 'name' => 'IntervalFast', 'caption' => 'Schnell-Intervall (Momentanwerte, Sekunden)', 'minimum' => 2, 'maximum' => 60, 'suffix' => 's'],
                        ['type' => 'NumberSpinner', 'name' => 'IntervalSlow', 'caption' => 'Langsam-Intervall (Energiezähler, Sekunden)', 'minimum' => 10, 'maximum' => 3600, 'suffix' => 's'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '📊  Datenpunkte',
                    'expanded' => true,
                    'items' => array_merge($groupItems, [
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'PowerInvert',
                            'caption' => 'Wirkleistung invertieren — falls Bezug/Einspeisung vertauscht angezeigt werden',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'EnergyUnitWh',
                            'caption' => 'Energie in Wh statt kWh ausgeben (Basiseinheit; die neue IPS-Darstellung skaliert dann selbst auf Wh/kWh/MWh)',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'WordSwap',
                            'caption' => 'Float-Wortreihenfolge tauschen (CDAB) — falls Messwerte unplausibel groß/klein sind (z. B. manche Phoenix EEM-XM)',
                        ],
                    ]),
                ],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Verbindung testen / Daten sofort lesen', 'onClick' => 'echo MHUB_TestConnection($id);'],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte Verbindung vervollständigen (IP-Adresse bzw. Inexogy-Anmeldung).'],
                ['code' => 102, 'icon' => 'active',   'caption' => 'Verbindung aktiv.'],
                ['code' => 201, 'icon' => 'error',    'caption' => 'Verbindungsfehler – Zähler nicht erreichbar.'],
            ],
        ];

        return json_encode($form);
    }

    /**
     * Blendet beim Umschalten des Zählertyps sofort die passende
     * Verbindungsart ein/aus — ohne "Übernehmen" abzuwarten. Ohne das würde
     * ein frisch auf Inexogy umgeschaltetes Formular weiterhin das
     * Host-Feld samt IP-Pflichtformat zeigen (GetConfigurationForm() läuft
     * nur beim Öffnen der Maske, nicht bei jeder Auswahländerung), mit
     * nichts Sinnvollem, was man dort eintragen könnte.
     */
    public function OnChangeMeter($meter)
    {
        $isCloud = in_array($meter, self::CLOUD_METERS, true);
        foreach (['InexogyIntro', 'InexogyEmail', 'InexogyPassword', 'InexogyLoginButton', 'InexogyMeterID', 'InexogyHintPoll', 'InexogyHintMigration', 'InexogyHintBackfill', 'InexogyBackfillDays', 'InexogyBackfillButton'] as $f) {
            $this->UpdateFormField($f, 'visible', $isCloud);
        }
        if (!$isCloud) {
            $this->UpdateFormField('InexogyResult', 'visible', false);
            $this->UpdateFormField('InexogyBackfillResult', 'visible', false);
        }
        $this->UpdateFormField('Host', 'visible', !$isCloud);
        // Nicht nur ausblenden, auch die Pflicht-Regex entschärfen — falls
        // sie an einem unsichtbaren Feld trotzdem noch griffe.
        $this->UpdateFormField('Host', 'validate', $isCloud ? '' : '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$');
        $this->UpdateFormField('Port', 'visible', !$isCloud);
        $this->UpdateFormField('UnitId', 'visible', !$isCloud);
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

    private function GetModbusClient(): MHUB_ModbusTcpClient
    {
        $mb = new MHUB_ModbusTcpClient(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyInteger('UnitId')
        );
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

    /**
     * Führt den OAuth-Handshake mit E-Mail+Passwort aus, speichert danach NUR
     * die Tokens (Attribute) und leert das Passwort-Property. Anschließend wird
     * die Zählerliste des Kontos geholt und als Auswahl angeboten. Rückgabe-
     * texte gehen nur ins Formularfeld — Passwort/Token nie in Log/Anzeige.
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
        if ($email === '' || $pass === '') {
            $say('❌ Bitte zuerst E-Mail und Passwort eintragen und übernehmen, dann anmelden.');
            return;
        }

        $c = new MHUB_InexogyClient();
        if (!$c->registerConsumer('IP-Symcon MeterHub ' . $this->InstanceID)) {
            $say('❌ Anmeldung fehlgeschlagen bei der Registrierung (Schritt 1/4). Ist die Inexogy-API erreichbar? (' . $c->getLastError() . ')');
            return;
        }
        if (!$c->fetchRequestToken()) {
            $say('❌ Anmeldung fehlgeschlagen beim Anforderungs-Token (Schritt 2/4). (' . $c->getLastError() . ')');
            return;
        }
        $verifier = $c->authorize($email, $pass);
        if ($verifier === '') {
            $say('❌ Anmeldung fehlgeschlagen bei der Autorisierung (Schritt 3/4). E-Mail oder Passwort falsch? (' . $c->getLastError() . ')');
            return;
        }
        if (!$c->fetchAccessToken($verifier)) {
            $say('❌ Anmeldung fehlgeschlagen beim Zugriffs-Token (Schritt 4/4). (' . $c->getLastError() . ')');
            return;
        }

        // Erfolg: Tokens sichern, Passwort verwerfen.
        $this->WriteAttributeString('InexogyConsumerKey',    $c->getConsumerKey());
        $this->WriteAttributeString('InexogyConsumerSecret', $c->getConsumerSecret());
        $this->WriteAttributeString('InexogyToken',          $c->getToken());
        $this->WriteAttributeString('InexogyTokenSecret',    $c->getTokenSecret());
        IPS_SetProperty($this->InstanceID, 'InexogyPassword', '');
        IPS_ApplyChanges($this->InstanceID);
        $this->UpdateFormField('InexogyPassword', 'value', '');

        $meters = $c->getMeters();
        if (!$meters) {
            $say('✅ Angemeldet, Tokens gespeichert, Passwort verworfen. Es wurden aber keine Zähler gefunden.');
            return;
        }
        $lines = ['✅ Angemeldet, Tokens gespeichert, Passwort verworfen. Gefundene Zähler:'];
        $opts  = [];
        foreach ($meters as $m) {
            $uid  = (string)($m['meterId'] ?? '');
            $sn   = (string)($m['serialNumber'] ?? ($m['fullSerialNumber'] ?? ''));
            $type = (string)($m['type'] ?? ($m['measurementType'] ?? ''));
            if ($uid === '') { continue; }
            $lines[] = '   • ' . ($sn !== '' ? $sn : $uid) . ($type !== '' ? " ($type)" : '');
            $opts[]  = ['caption' => ($sn !== '' ? $sn : $uid) . ($type !== '' ? " — $type" : ''), 'value' => $uid];
        }
        $lines[] = 'Bitte unten die Zähler-UID wählen und übernehmen.';
        $this->UpdateFormField('InexogyMeterID', 'options', json_encode($opts));
        $say(implode("\n", $lines));
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
            trigger_error('DiagnoseInexogyReadings: keine Inexogy-Instanz.', E_USER_WARNING);
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
        $say = function (string $m) {
            $this->UpdateFormField('InexogyBackfillResult', 'caption', $m);
            $this->UpdateFormField('InexogyBackfillResult', 'visible', true);
            trigger_error('BackfillInexogyArchive #' . $this->InstanceID . ': ' . $m, E_USER_NOTICE);
        };
        if (!in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true)) {
            $say('❌ Keine Inexogy-Instanz.');
            return;
        }
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) === 0) {
            $say('❌ Kein Archiv-Modul (Archive Control) auf dieser Installation gefunden.');
            return;
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
            $say('❌ Keine Zielvariable (energy_import/energy_export/power_total) vorhanden.');
            return;
        }

        $days       = max(1, $this->ReadPropertyInteger('InexogyBackfillDays'));
        $toOverall  = time();
        $fromOverall = $toOverall - $days * 86400;
        $chunkDays  = self::INEXOGY_BACKFILL_CHUNK_DAYS;
        $meterId    = $this->ReadPropertyString('InexogyMeterID');
        $c          = $this->GetTransport();

        $totals   = array_fill_keys(array_keys($vids), ['new' => 0, 'existing' => 0]);
        $emptyChunks = [];
        $chunkFrom = $fromOverall;
        while ($chunkFrom < $toOverall) {
            $chunkTo  = min($chunkFrom + $chunkDays * 86400, $toOverall);
            $readings = $c->getReadings($meterId, $chunkFrom * 1000, $chunkTo * 1000, 'fifteen_minutes');
            if (count($readings) === 0) {
                $emptyChunks[] = date('Y-m-d', $chunkFrom) . '–' . date('Y-m-d', $chunkTo) . ' (' . $c->getLastError() . ')';
                $chunkFrom = $chunkTo;
                continue;
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
                foreach ($readings as $r) {
                    $raw = $r['values'][$field] ?? null;
                    if ($raw === null) {
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

        // Aggregation erst einmal am Ende neu bilden, nicht je Block —
        // AC_ReAggregateVariable() bezieht sich ohnehin auf die ganze
        // Variable, wiederholtes Aufrufen je Block wäre nur unnötige Last.
        foreach ($vids as $field => $vid) {
            if ($totals[$field]['new'] > 0) {
                AC_ReAggregateVariable($archiveID, $vid);
            }
        }

        $out = ["Zeitraum: " . date('Y-m-d', $fromOverall) . ' – ' . date('Y-m-d', $toOverall) . " ({$days} Tage, in {$chunkDays}-Tage-Blöcken verarbeitet)"];
        foreach ($fields as $field => $t) {
            if (!isset($vids[$field])) {
                $out[] = $t['ident'] . ': Variable nicht vorhanden, übersprungen.';
                continue;
            }
            $out[] = $t['ident'] . ' (#' . $vids[$field] . '): ' . $totals[$field]['new'] . ' neue Werte nachgetragen (' .
                $totals[$field]['existing'] . ' schon vorhanden, übersprungen)';
        }
        if ($emptyChunks) {
            $out[] = 'Ohne Daten von Inexogy: ' . implode('; ', $emptyChunks);
        }
        $say(implode("\n", $out));
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

    private function IsPerPhaseMode(): bool
    {
        return $this->ReadPropertyString('MeasureMode') === 'perphase';
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
            $custom = trim($custom);
            $out[] = [
                'slot'   => $slot,
                'key'    => $key,
                'label'  => $custom !== '' ? $custom : $name,
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
            $defs[] = ['fn_' . $slug . '_power',  $a['label'] . ' — Leistung',       'F', 'NRG.Watt',   true, 'function', $a['icon'], $a['power']];
            $defs[] = ['fn_' . $slug . '_import', $a['label'] . ' — Bezug',          'F', 'NRG.kWh', true, 'function', $a['icon'], $a['import']];
            $defs[] = ['fn_' . $slug . '_export', $a['label'] . ' — Einspeisung',    'F', 'NRG.kWh', true, 'function', $a['icon'], $a['export']];
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
    public function GetFunctions(): string
    {
        // Zähler-Eigenschaften einmal bestimmen — sie gelten für die ganze
        // Instanz (ein Zähler ist als Ganzes echtzeitnah/träge bzw. abrechnungs-
        // verbindlich, auch im Drei-Phasen-Modus mit drei Zuordnungen).
        $latency      = in_array($this->ReadPropertyString('Meter'), self::CLOUD_METERS, true) ? 'delayed' : 'realtime';
        $authority    = $this->ReadPropertyBoolean('BillingGrade') ? 'billing' : 'auxiliary';
        $pollInterval = $this->ReadPropertyInteger('IntervalFast');

        $list = [];
        foreach ($this->FunctionAssignments() as $a) {
            $list[] = [
                'slot'            => $a['slot'],
                'function'        => $a['key'],
                'label'           => $a['label'],
                'powerID'         => $this->FindVarByIdent($a['power']),
                'energyImportID'  => $this->FindVarByIdent($a['import']),
                'energyExportID'  => $this->FindVarByIdent($a['export']),
                // Alle bisherigen Treiber liefern kumulative Zählerstände.
                'energyKind'      => 'counter',
                // Zähler-Eigenschaften in JEDE Zuordnung gespiegelt (identisch
                // zur Instanz-Ebene). So kann ein Konsument, der über
                // assignments[] iteriert und nach function filtert, authority/
                // latency direkt an der Zuordnung lesen, ohne zum Instanz-Objekt
                // zurückzugreifen. Beide Orte stammen aus derselben Property —
                // sie können nicht auseinanderlaufen.
                'latency'         => $latency,
                'authority'       => $authority,
                'pollInterval'    => $pollInterval,
            ];
        }
        return json_encode([
            // Vertragsversion (Verbund-Konvention): 1.0 = Ur-Vertrag,
            // 1.1 = latency/authority/pollInterval/energyKind-Erweiterung.
            // Additiv; Major nur bei Bruch, volle Kompatibilität innerhalb
            // derselben Major. Fehlt das Feld, ist konservativ '1.0' anzunehmen.
            'contractVersion' => '1.1',
            'instanceID'  => $this->InstanceID,
            'meter'       => $this->ReadPropertyString('Meter'),
            'measureMode' => $this->ReadPropertyString('MeasureMode'),
            'latency'     => $latency,
            'authority'   => $authority,
            'pollInterval'=> $pollInterval,
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

    private function RegisterVariables()
    {
        $driver = $this->GetDriver();

        // Funktionszuordnung einmalig auflösen (wird in RegisterVar genutzt).
        $this->funcMap = $this->FunctionByIdent();
        $mirrors = $this->MirrorDefs();

        $valid = [];
        foreach ($driver->getBaseVars() as $v) {
            $valid[$v[0]] = true;
        }
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            if ($this->ReadPropertyBoolean($propName)) {
                foreach ($group['vars'] as $v) {
                    $valid[$v[0]] = true;
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
        }
        $this->PruneForeignObjects($valid);

        $pos = 0;
        foreach ($driver->getBaseVars() as $v) {
            $this->RegisterVar($v, $pos++);
        }
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            if ($this->ReadPropertyBoolean($propName)) {
                foreach ($group['vars'] as $v) {
                    $this->RegisterVar($v, $pos++);
                }
            }
        }
        // Sammel-Variablen je Funktion (Spiegel der zugeordneten Kanäle).
        foreach ($mirrors as $d) {
            $this->RegisterVar([$d[0], $d[1], $d[2], $d[3], $d[4], $d[5], ''], $pos++);
            $vid = $this->FindVarByIdent($d[0]);
            if ($vid && $d[6] !== '') {
                @IPS_SetIcon($vid, $d[6]);
            }
        }
    }

    // Entfernt Variablen eines anderen Treibers / deaktivierter Gruppe und
    // räumt danach leere Modul-Kategorien (cat_*) ab. (Wie InverterHub.)
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
                @IPS_DeleteVariable($cid);
            }
        }

        foreach ($all as $cid) {
            if (!IPS_ObjectExists($cid)) {
                continue;
            }
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] === 0
                && strpos($obj['ObjectIdent'], 'cat_') === 0
                && count(IPS_GetChildrenIDs($cid)) === 0) {
                @IPS_DeleteCategory($cid);
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

    private function RegisterVar(array $def, int $pos)
    {
        [$ident, $caption, $type, $profile, $archive, $group] = $def;
        $reg = isset($def[6]) ? $def[6] : '';

        $vtype = $this->VarTypeFor($type);

        $vid = $this->FindVarByIdent($ident);
        if ($vid && IPS_GetVariable($vid)['VariableType'] !== $vtype) {
            @IPS_DeleteVariable($vid);
            $vid = 0;
        }
        if (!$vid) {
            $vid = IPS_CreateVariable($vtype);
            IPS_SetIdent($vid, $ident);
        }

        $catID = $this->EnsureCategory($group);
        IPS_SetParent($vid, $catID);
        IPS_SetPosition($vid, $pos);

        // Funktionszuordnung: Bezeichnung voranstellen und passendes Icon
        // setzen, damit im Objektbaum direkt „Wärmepumpe — …" steht.
        if (isset($this->funcMap[$ident])) {
            $caption = $this->funcMap[$ident]['label'] . ' — ' . $caption;
            if ($this->funcMap[$ident]['icon'] !== '') {
                @IPS_SetIcon($vid, $this->funcMap[$ident]['icon']);
            }
        }
        IPS_SetName($vid, $caption);

        // Energie in Wh: statt kWh-Profil das Wh-Profil setzen (×1000 macht
        // SetVarEnergyWh beim Schreiben).
        if ($profile === 'NRG.kWh' && $this->ReadPropertyBoolean('EnergyUnitWh')) {
            $profile = 'MHB.Wh';
        }
        if ($profile !== '' && @IPS_GetVariable($vid)['VariableCustomProfile'] !== $profile) {
            IPS_SetVariableCustomProfile($vid, $profile);
        }
        if ($reg !== '') {
            IPS_SetInfo($vid, $reg);
        }
        if ($archive) {
            $this->SetArchive($vid);
        }
    }

    private const CATEGORY_LABELS = [
        'total'   => 'Summenwerte',
        'voltage' => 'Spannung',
        'current' => 'Strom',
        'power'   => 'Leistung je Phase',
        'energy'  => 'Energiezähler',
        'function' => 'Funktionen',
        'quality' => 'Netzqualität',
        'device'  => 'Gerät',
        'errors'  => 'Fehler / Verbindung',
    ];

    private function EnsureCategory($key)
    {
        $catIdent = 'cat_' . $key;
        $catID = $this->FindIdentRecursive($this->InstanceID, $catIdent);
        if (!$catID) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, $catIdent);
            $pos = array_search($key, array_keys(self::CATEGORY_LABELS));
            IPS_SetPosition($catID, $pos !== false ? $pos : 99);
        }
        IPS_SetName($catID, self::CATEGORY_LABELS[$key] ?? $key);
        return $catID;
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

    private function SetArchive($vid)
    {
        $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archiveIDs) > 0) {
            AC_SetLoggingStatus($archiveIDs[0], $vid, true);
            AC_SetAggregationType($archiveIDs[0], $vid, 0);
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
        $vid = $this->FindVarByIdent($ident);
        if (!$vid) {
            return;
        }
        if ($this->ReadPropertyBoolean('EnergyUnitWh')) {
            SetValueFloat($vid, $wh);
        } else {
            SetValueFloat($vid, $wh / 1000.0);
        }
    }

    // Energie-Setter für Zähler, die bereits kWh liefern (z. B. Eastron).
    // Standard-Ausgabe kWh; ist „Energie in Wh" aktiv, auf Wh hochrechnen.
    public function SetVarEnergykWh(string $ident, float $kwh)
    {
        if (!is_finite($kwh)) {
            $kwh = 0.0;
        }
        $vid = $this->FindVarByIdent($ident);
        if (!$vid) {
            return;
        }
        if ($this->ReadPropertyBoolean('EnergyUnitWh')) {
            SetValueFloat($vid, $kwh * 1000.0);
        } else {
            SetValueFloat($vid, $kwh);
        }
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
                IPS_SetVariableProfileAssociation($name, $value, $label, '', $color);
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
