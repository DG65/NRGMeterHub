<?php

// ===========================================================================
// MeterHub — generisches Modbus-TCP-Framework für Energiezähler verschiedener
// Hersteller. Ein Modul, ein Auswahlfeld „Zählertyp" — je nach Auswahl werden
// die passenden Datenpunkt-Gruppen und Register freigeschaltet.
//
// Aufbau analog zu InverterHub:
//   ModbusTcpClient        — gemeinsame Modbus-TCP-Grundfunktionen
//   MeterDriverInterface   — Vertrag, den jeder Zähler-Treiber erfüllt
//   Pac2200Driver          — Siemens SENTRON PAC2200
//   Umg604Driver           — Janitza UMG 604(-PRO)
//   MeterHub               — Hauptmodul, lädt den Treiber laut Meter-Property
//
// Zähler werden nur gelesen (kein writeControl) — daher ist das Interface
// schlanker als beim InverterHub.
// ===========================================================================

class ModbusTcpClient
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
// MeterDriverInterface — Vertrag, den jeder Zähler-Treiber erfüllt
// ---------------------------------------------------------------------------

interface MeterDriverInterface
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
// Pac2200Driver — Siemens SENTRON PAC2200
// Float32-Messgrößen ab Register 1, Energiezähler als Double ab Register 801.
// Registeradressen laut Gerätehandbuch L1V30415167A (FC 0x03).
// ---------------------------------------------------------------------------

class Pac2200Driver implements MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung gesamt',       'F', 'MHB.W',   true,  'total',  'FC3 65 (Σ P)'],
            ['voltage_avg',   'Spannung Ø (L-N)',          'F', 'MHB.V',   false, 'total',  'FC3 57'],
            ['current_avg',   'Strom Ø',                   'F', 'MHB.A',   false, 'total',  'FC3 61'],
            ['frequency',     'Frequenz',                  'F', 'MHB.Hz',  false, 'total',  'FC3 55'],
            ['energy_import', 'Wirkarbeit Bezug (Tarif 1)','F', 'MHB.kWh', true,  'energy', 'FC3 801 (Wh)'],
            ['energy_export', 'Wirkarbeit Abgabe (Tarif 1)','F','MHB.kWh', true,  'energy', 'FC3 809 (Wh)'],
            ['connected',     'Verbindung',                'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N, L-L)', 'vars' => [
                ['u_l1_n',  'Spannung L1-N',  'F', 'MHB.V', false, 'voltage', 'FC3 1'],
                ['u_l2_n',  'Spannung L2-N',  'F', 'MHB.V', false, 'voltage', 'FC3 3'],
                ['u_l3_n',  'Spannung L3-N',  'F', 'MHB.V', false, 'voltage', 'FC3 5'],
                ['u_l1_l2', 'Spannung L1-L2', 'F', 'MHB.V', false, 'voltage', 'FC3 7'],
                ['u_l2_l3', 'Spannung L2-L3', 'F', 'MHB.V', false, 'voltage', 'FC3 9'],
                ['u_l3_l1', 'Spannung L3-L1', 'F', 'MHB.V', false, 'voltage', 'FC3 11'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase (+ Neutralleiter)', 'vars' => [
                ['i_l1', 'Strom L1',           'F', 'MHB.A', false, 'current', 'FC3 13'],
                ['i_l2', 'Strom L2',           'F', 'MHB.A', false, 'current', 'FC3 15'],
                ['i_l3', 'Strom L3',           'F', 'MHB.A', false, 'current', 'FC3 17'],
                ['i_n',  'Neutralleiterstrom', 'F', 'MHB.A', false, 'current', 'FC3 71'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'MHB.W', false, 'power', 'FC3 25'],
                ['p_l2', 'Wirkleistung L2', 'F', 'MHB.W', false, 'power', 'FC3 27'],
                ['p_l3', 'Wirkleistung L3', 'F', 'MHB.W', false, 'power', 'FC3 29'],
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
                ['energy_import_t2', 'Wirkarbeit Bezug (Tarif 2)',  'F', 'MHB.kWh', true, 'energy', 'FC3 805 (Wh)'],
                ['energy_export_t2', 'Wirkarbeit Abgabe (Tarif 2)', 'F', 'MHB.kWh', true, 'energy', 'FC3 813 (Wh)'],
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
// JanitzaClassicDriver — klassische Janitza-UMG-Registerkarte (19000er-Block)
// Deckt UMG 604, 605-PRO, 509-PRO, 512-PRO, 806, 96PA und 801 ab — alle nutzen
// dieselbe feste Firmware-Karte: Float32-Messgrößen ab Register 19000, Energie
// als Float32 (Wh) bei 19068 (Bezug) / 19076 (Abgabe), Netzqualität (THD) ab
// 19110, Drehfeld 19052. FC 0x03. Ø-Spannung/-Strom werden aus den Phasen
// berechnet (statt aus dem optionalen 19630-Mittelwertblock, den nicht jedes
// Modell dieser Familie führt — z. B. der UMG 96PA nicht).
// ---------------------------------------------------------------------------

class JanitzaClassicDriver implements MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung gesamt',        'F', 'MHB.W',   true,  'total',  'FC3 19026 (Psum3)'],
            ['voltage_avg',   'Spannung Ø (L-N)',           'F', 'MHB.V',   false, 'total',  'FC3 19000/02/04 Ø'],
            ['current_avg',   'Strom Ø',                    'F', 'MHB.A',   false, 'total',  'FC3 19012/14/16 Ø'],
            ['frequency',     'Frequenz',                   'F', 'MHB.Hz',  false, 'total',  'FC3 19050'],
            ['energy_import', 'Wirkarbeit Bezug',           'F', 'MHB.kWh', true,  'energy', 'FC3 19068 (Wh)'],
            ['energy_export', 'Wirkarbeit Abgabe',          'F', 'MHB.kWh', true,  'energy', 'FC3 19076 (Wh)'],
            ['connected',     'Verbindung',                 'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N, L-L)', 'vars' => [
                ['u_l1_n',  'Spannung L1-N',  'F', 'MHB.V', false, 'voltage', 'FC3 19000'],
                ['u_l2_n',  'Spannung L2-N',  'F', 'MHB.V', false, 'voltage', 'FC3 19002'],
                ['u_l3_n',  'Spannung L3-N',  'F', 'MHB.V', false, 'voltage', 'FC3 19004'],
                ['u_l1_l2', 'Spannung L1-L2', 'F', 'MHB.V', false, 'voltage', 'FC3 19006'],
                ['u_l2_l3', 'Spannung L2-L3', 'F', 'MHB.V', false, 'voltage', 'FC3 19008'],
                ['u_l3_l1', 'Spannung L3-L1', 'F', 'MHB.V', false, 'voltage', 'FC3 19010'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase (+ Summe)', 'vars' => [
                ['i_l1', 'Strom L1',       'F', 'MHB.A', false, 'current', 'FC3 19012'],
                ['i_l2', 'Strom L2',       'F', 'MHB.A', false, 'current', 'FC3 19014'],
                ['i_l3', 'Strom L3',       'F', 'MHB.A', false, 'current', 'FC3 19016'],
                ['i_sum', 'Strom Summe (I1+I2+I3)', 'F', 'MHB.A', false, 'current', 'FC3 19018'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'MHB.W', false, 'power', 'FC3 19020'],
                ['p_l2', 'Wirkleistung L2', 'F', 'MHB.W', false, 'power', 'FC3 19022'],
                ['p_l3', 'Wirkleistung L3', 'F', 'MHB.W', false, 'power', 'FC3 19024'],
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
                ['thd_u_l1', 'THD Spannung L1', 'F', 'MHB.Percent', false, 'quality', 'FC3 19110'],
                ['thd_u_l2', 'THD Spannung L2', 'F', 'MHB.Percent', false, 'quality', 'FC3 19112'],
                ['thd_u_l3', 'THD Spannung L3', 'F', 'MHB.Percent', false, 'quality', 'FC3 19114'],
                ['thd_i_l1', 'THD Strom L1',    'F', 'MHB.Percent', false, 'quality', 'FC3 19116'],
                ['thd_i_l2', 'THD Strom L2',    'F', 'MHB.Percent', false, 'quality', 'FC3 19118'],
                ['thd_i_l3', 'THD Strom L3',    'F', 'MHB.Percent', false, 'quality', 'FC3 19120'],
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
// Umg800Driver — Janitza UMG 800 (neue Generation)
// Der UMG 800 hat eine frei konfigurierbare Modbus-Registerkarte; dieser
// Treiber folgt der ausgelieferten Werks-Standardzuordnung (VirtualMeter
// „Group19"). Sie liegt zwar auch im 19000er-Bereich, ist aber ANDERS
// aufgebaut als die klassische Karte: Summen-Wirkleistung 19030, Frequenz
// 19054, Bezug 19072, Abgabe 19080; zwischen 19019 und 19024 liegt eine Lücke.
// Wurde die Modbus-Zuordnung im Gerät geändert, stimmen diese Adressen nicht.
// ---------------------------------------------------------------------------

class Umg800Driver implements MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung gesamt', 'F', 'MHB.W',   true,  'total',  'FC3 19030 (Σ P)'],
            ['voltage_avg',   'Spannung Ø (L-N)',    'F', 'MHB.V',   false, 'total',  'FC3 19000/02/04 Ø'],
            ['current_avg',   'Strom Ø',             'F', 'MHB.A',   false, 'total',  'FC3 19012/14/16 Ø'],
            ['frequency',     'Frequenz',            'F', 'MHB.Hz',  false, 'total',  'FC3 19054'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'MHB.kWh', true,  'energy', 'FC3 19072 (Wh)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'MHB.kWh', true,  'energy', 'FC3 19080 (Wh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N, L-L)', 'vars' => [
                ['u_l1_n',  'Spannung L1-N',  'F', 'MHB.V', false, 'voltage', 'FC3 19000'],
                ['u_l2_n',  'Spannung L2-N',  'F', 'MHB.V', false, 'voltage', 'FC3 19002'],
                ['u_l3_n',  'Spannung L3-N',  'F', 'MHB.V', false, 'voltage', 'FC3 19004'],
                ['u_l1_l2', 'Spannung L1-L2', 'F', 'MHB.V', false, 'voltage', 'FC3 19006'],
                ['u_l2_l3', 'Spannung L2-L3', 'F', 'MHB.V', false, 'voltage', 'FC3 19008'],
                ['u_l3_l1', 'Spannung L3-L1', 'F', 'MHB.V', false, 'voltage', 'FC3 19010'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase (+ I4)', 'vars' => [
                ['i_l1',  'Strom L1', 'F', 'MHB.A', false, 'current', 'FC3 19012'],
                ['i_l2',  'Strom L2', 'F', 'MHB.A', false, 'current', 'FC3 19014'],
                ['i_l3',  'Strom L3', 'F', 'MHB.A', false, 'current', 'FC3 19016'],
                ['i_sum', 'Strom I4', 'F', 'MHB.A', false, 'current', 'FC3 19018'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'MHB.W', false, 'power', 'FC3 19024'],
                ['p_l2', 'Wirkleistung L2', 'F', 'MHB.W', false, 'power', 'FC3 19026'],
                ['p_l3', 'Wirkleistung L3', 'F', 'MHB.W', false, 'power', 'FC3 19028'],
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
                ['thd_u_l1', 'THD Spannung L1', 'F', 'MHB.Percent', false, 'quality', 'FC3 19114'],
                ['thd_u_l2', 'THD Spannung L2', 'F', 'MHB.Percent', false, 'quality', 'FC3 19116'],
                ['thd_u_l3', 'THD Spannung L3', 'F', 'MHB.Percent', false, 'quality', 'FC3 19118'],
                ['thd_i_l1', 'THD Strom L1',    'F', 'MHB.Percent', false, 'quality', 'FC3 19120'],
                ['thd_i_l2', 'THD Strom L2',    'F', 'MHB.Percent', false, 'quality', 'FC3 19122'],
                ['thd_i_l3', 'THD Strom L3',    'F', 'MHB.Percent', false, 'quality', 'FC3 19124'],
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
// EastronSdmDriver — Eastron SDM72D-M v2
// FC 0x04 (Input Register), Float32 Big-Endian, Basisadresse 0. Energie in kWh.
// Registerkarte laut IP-Symcon-Forum-Vorlage / Eastron-Handbuch.
// ---------------------------------------------------------------------------

class EastronSdmDriver implements MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung gesamt', 'F', 'MHB.W',   true,  'total',  'FC4 52'],
            ['voltage_avg',   'Spannung Ø (L-N)',    'F', 'MHB.V',   false, 'total',  'FC4 42'],
            ['current_avg',   'Strom Ø',             'F', 'MHB.A',   false, 'total',  'FC4 46'],
            ['frequency',     'Frequenz',            'F', 'MHB.Hz',  false, 'total',  'FC4 70'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'MHB.kWh', true,  'energy', 'FC4 72 (kWh)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'MHB.kWh', true,  'energy', 'FC4 74 (kWh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N, L-L)', 'vars' => [
                ['u_l1_n',  'Spannung L1-N',  'F', 'MHB.V', false, 'voltage', 'FC4 0'],
                ['u_l2_n',  'Spannung L2-N',  'F', 'MHB.V', false, 'voltage', 'FC4 2'],
                ['u_l3_n',  'Spannung L3-N',  'F', 'MHB.V', false, 'voltage', 'FC4 4'],
                ['u_l1_l2', 'Spannung L1-L2', 'F', 'MHB.V', false, 'voltage', 'FC4 200'],
                ['u_l2_l3', 'Spannung L2-L3', 'F', 'MHB.V', false, 'voltage', 'FC4 202'],
                ['u_l3_l1', 'Spannung L3-L1', 'F', 'MHB.V', false, 'voltage', 'FC4 204'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase (+ Neutralleiter)', 'vars' => [
                ['i_l1', 'Strom L1',           'F', 'MHB.A', false, 'current', 'FC4 6'],
                ['i_l2', 'Strom L2',           'F', 'MHB.A', false, 'current', 'FC4 8'],
                ['i_l3', 'Strom L3',           'F', 'MHB.A', false, 'current', 'FC4 10'],
                ['i_n',  'Neutralleiterstrom', 'F', 'MHB.A', false, 'current', 'FC4 224'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'MHB.W', false, 'power', 'FC4 12'],
                ['p_l2', 'Wirkleistung L2', 'F', 'MHB.W', false, 'power', 'FC4 14'],
                ['p_l3', 'Wirkleistung L3', 'F', 'MHB.W', false, 'power', 'FC4 16'],
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
// WhatWattDriver — WhatWatt Smart Meter
// FC 0x04, Float32 (Momentanwerte, Energie-Summen) + Double (Tarif-Energie),
// Big-Endian. Wirkleistung getrennt als Bezug (501) und Abgabe (505) →
// Gesamt = Bezug − Abgabe. Keine Frequenz in der Vorlage.
// ---------------------------------------------------------------------------

class WhatWattDriver implements MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung gesamt', 'F', 'MHB.W',   true,  'total',  'FC4 501−505'],
            ['voltage_avg',   'Spannung Ø',          'F', 'MHB.V',   false, 'total',  'FC4 1/3/5 Ø'],
            ['current_avg',   'Strom Ø',             'F', 'MHB.A',   false, 'total',  'FC4 13/15/17 Ø'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'MHB.kWh', true,  'energy', 'FC4 549 (Wh)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'MHB.kWh', true,  'energy', 'FC4 553 (Wh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase', 'vars' => [
                ['u_l1_n', 'Spannung L1', 'F', 'MHB.V', false, 'voltage', 'FC4 1'],
                ['u_l2_n', 'Spannung L2', 'F', 'MHB.V', false, 'voltage', 'FC4 3'],
                ['u_l3_n', 'Spannung L3', 'F', 'MHB.V', false, 'voltage', 'FC4 5'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase', 'vars' => [
                ['i_l1', 'Strom L1', 'F', 'MHB.A', false, 'current', 'FC4 13'],
                ['i_l2', 'Strom L2', 'F', 'MHB.A', false, 'current', 'FC4 15'],
                ['i_l3', 'Strom L3', 'F', 'MHB.A', false, 'current', 'FC4 17'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'MHB.W', false, 'power', 'FC4 25'],
                ['p_l2', 'Wirkleistung L2', 'F', 'MHB.W', false, 'power', 'FC4 27'],
                ['p_l3', 'Wirkleistung L3', 'F', 'MHB.W', false, 'power', 'FC4 29'],
            ]],
            'GroupTariff2' => ['caption' => 'Energie nach Tarif (T1/T2)', 'vars' => [
                ['energy_import_t1', 'Bezug Tarif 1',  'F', 'MHB.kWh', true, 'energy', 'FC4 801 (Wh)'],
                ['energy_import_t2', 'Bezug Tarif 2',  'F', 'MHB.kWh', true, 'energy', 'FC4 805 (Wh)'],
                ['energy_export_t1', 'Abgabe Tarif 1', 'F', 'MHB.kWh', true, 'energy', 'FC4 809 (Wh)'],
                ['energy_export_t2', 'Abgabe Tarif 2', 'F', 'MHB.kWh', true, 'energy', 'FC4 813 (Wh)'],
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
// PhoenixEem375Driver — Phoenix Contact EEM-EM375
// FC 0x04, Float32 Big-Endian, Basisadresse 4096. Nur Bezugsenergie (Wh).
// ---------------------------------------------------------------------------

class PhoenixEem375Driver implements MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung gesamt', 'F', 'MHB.W',   true,  'total',  'FC4 4134'],
            ['voltage_avg',   'Spannung Ø (L-N)',    'F', 'MHB.V',   false, 'total',  'FC4 4096/98/100 Ø'],
            ['current_avg',   'Strom Ø',             'F', 'MHB.A',   false, 'total',  'FC4 4110/12/14 Ø'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'MHB.kWh', true,  'energy', 'FC4 4358 (Wh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N)', 'vars' => [
                ['u_l1_n', 'Spannung L1-N', 'F', 'MHB.V', false, 'voltage', 'FC4 4096'],
                ['u_l2_n', 'Spannung L2-N', 'F', 'MHB.V', false, 'voltage', 'FC4 4098'],
                ['u_l3_n', 'Spannung L3-N', 'F', 'MHB.V', false, 'voltage', 'FC4 4100'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase', 'vars' => [
                ['i_l1', 'Strom I1', 'F', 'MHB.A', false, 'current', 'FC4 4110'],
                ['i_l2', 'Strom I2', 'F', 'MHB.A', false, 'current', 'FC4 4112'],
                ['i_l3', 'Strom I3', 'F', 'MHB.A', false, 'current', 'FC4 4114'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'MHB.W', false, 'power', 'FC4 4128'],
                ['p_l2', 'Wirkleistung L2', 'F', 'MHB.W', false, 'power', 'FC4 4130'],
                ['p_l3', 'Wirkleistung L3', 'F', 'MHB.W', false, 'power', 'FC4 4132'],
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
// PhoenixEemXmDriver — Phoenix Contact EEM-XM (xMxxx-Reihe)
// FC 0x04, Float32, Basisadresse 32774. Anordnung weicht vom EM375 ab; einige
// XM-Geräte liefern die Wörter getauscht — ggf. den WordSwap-Schalter nutzen.
// ---------------------------------------------------------------------------

class PhoenixEemXmDriver implements MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung gesamt', 'F', 'MHB.W',   true,  'total',  'FC4 32790'],
            ['voltage_avg',   'Spannung Ø (L-N)',    'F', 'MHB.V',   false, 'total',  'FC4 32774/76/78 Ø'],
            ['current_avg',   'Strom Ø',             'F', 'MHB.A',   false, 'total',  'FC4 32782/84/86 Ø'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'MHB.kWh', true,  'energy', 'FC4 37630 (Wh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N)', 'vars' => [
                ['u_l1_n', 'Spannung L1-N', 'F', 'MHB.V', false, 'voltage', 'FC4 32774'],
                ['u_l2_n', 'Spannung L2-N', 'F', 'MHB.V', false, 'voltage', 'FC4 32776'],
                ['u_l3_n', 'Spannung L3-N', 'F', 'MHB.V', false, 'voltage', 'FC4 32778'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase', 'vars' => [
                ['i_l1', 'Strom I1', 'F', 'MHB.A', false, 'current', 'FC4 32782'],
                ['i_l2', 'Strom I2', 'F', 'MHB.A', false, 'current', 'FC4 32784'],
                ['i_l3', 'Strom I3', 'F', 'MHB.A', false, 'current', 'FC4 32786'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'MHB.W', false, 'power', 'FC4 32798'],
                ['p_l2', 'Wirkleistung L2', 'F', 'MHB.W', false, 'power', 'FC4 32800'],
                ['p_l3', 'Wirkleistung L3', 'F', 'MHB.W', false, 'power', 'FC4 32802'],
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
// CarloGavazziDriver — Carlo Gavazzi EM24 / EM300 / ET340
// FC 0x04, Int32 mit getauschter Wortreihenfolge (CDAB), Skalierung:
// U ×0,1 V · I ×0,001 A · P ×0,1 W · f ×0,1 Hz · Energie ×0,1 kWh.
// Registerkarte nach OpenEMS (io.openems.edge.meter.carlo.gavazzi.em300).
// ---------------------------------------------------------------------------

class CarloGavazziDriver implements MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung gesamt', 'F', 'MHB.W',   true,  'total',  'FC4 40 (×0,1)'],
            ['voltage_avg',   'Spannung Ø (L-N)',    'F', 'MHB.V',   false, 'total',  'FC4 0/2/4 Ø'],
            ['current_avg',   'Strom Ø',             'F', 'MHB.A',   false, 'total',  'FC4 12/14/16 Ø'],
            ['frequency',     'Frequenz',            'F', 'MHB.Hz',  false, 'total',  'FC4 51 (×0,1)'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'MHB.kWh', true,  'energy', 'FC4 52 (×0,1 kWh)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'MHB.kWh', true,  'energy', 'FC4 78 (×0,1 kWh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N)', 'vars' => [
                ['u_l1_n', 'Spannung L1-N', 'F', 'MHB.V', false, 'voltage', 'FC4 0'],
                ['u_l2_n', 'Spannung L2-N', 'F', 'MHB.V', false, 'voltage', 'FC4 2'],
                ['u_l3_n', 'Spannung L3-N', 'F', 'MHB.V', false, 'voltage', 'FC4 4'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase', 'vars' => [
                ['i_l1', 'Strom L1', 'F', 'MHB.A', false, 'current', 'FC4 12'],
                ['i_l2', 'Strom L2', 'F', 'MHB.A', false, 'current', 'FC4 14'],
                ['i_l3', 'Strom L3', 'F', 'MHB.A', false, 'current', 'FC4 16'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'MHB.W', false, 'power', 'FC4 18'],
                ['p_l2', 'Wirkleistung L2', 'F', 'MHB.W', false, 'power', 'FC4 20'],
                ['p_l3', 'Wirkleistung L3', 'F', 'MHB.W', false, 'power', 'FC4 22'],
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
// SocomecCountisDriver — Socomec Countis E23/E24/E27/E28/E34/E44 (EXPERIMENTELL)
// FC 0x03, Big-Endian; U als UInt32 ×0,01 V, I UInt32 ×0,001 A, f UInt32
// ×0,001 Hz, P/Q Int32 ×10 W/var, Energie UInt32 ×0,01 kWh. Skalen nach
// OpenEMS abgeleitet — an echtem Gerät prüfen (v. a. Leistungs-Skala).
// ---------------------------------------------------------------------------

class SocomecCountisDriver implements MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung gesamt', 'F', 'MHB.W',   true,  'total',  'FC3 0xC568 (×10)'],
            ['voltage_avg',   'Spannung Ø (L-N)',    'F', 'MHB.V',   false, 'total',  'FC3 0xC558/5A/5C Ø'],
            ['current_avg',   'Strom Ø',             'F', 'MHB.A',   false, 'total',  'FC3 0xC560/62/64 Ø'],
            ['frequency',     'Frequenz',            'F', 'MHB.Hz',  false, 'total',  'FC3 0xC55E'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'MHB.kWh', true,  'energy', 'FC3 0xC702 (×0,01)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'MHB.kWh', true,  'energy', 'FC3 0xC708 (×0,01)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase (L-N)', 'vars' => [
                ['u_l1_n', 'Spannung L1-N', 'F', 'MHB.V', false, 'voltage', 'FC3 0xC558'],
                ['u_l2_n', 'Spannung L2-N', 'F', 'MHB.V', false, 'voltage', 'FC3 0xC55A'],
                ['u_l3_n', 'Spannung L3-N', 'F', 'MHB.V', false, 'voltage', 'FC3 0xC55C'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase', 'vars' => [
                ['i_l1', 'Strom L1', 'F', 'MHB.A', false, 'current', 'FC3 0xC560'],
                ['i_l2', 'Strom L2', 'F', 'MHB.A', false, 'current', 'FC3 0xC562'],
                ['i_l3', 'Strom L3', 'F', 'MHB.A', false, 'current', 'FC3 0xC564'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'MHB.W', false, 'power', 'FC3 0xC570'],
                ['p_l2', 'Wirkleistung L2', 'F', 'MHB.W', false, 'power', 'FC3 0xC572'],
                ['p_l3', 'Wirkleistung L3', 'F', 'MHB.W', false, 'power', 'FC3 0xC574'],
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
// MbsProfessionalDriver — MBS Professional 3-75 M-Bus/Modbus-Gateway (EXPERIMENTELL)
// FC 0x03, Big-Endian. Aus den IP-Symcon-Forum-Vorlagen abgeleitet: Bezug/
// Abgabe als UInt32 ×0,001 kWh, Wirkleistung Int32 (W), Spannung/Frequenz
// UInt16 ×0,1. Integer-Typgrößen aus den Vorlagen abgeleitet — an echtem
// Gateway prüfen.
// ---------------------------------------------------------------------------

class MbsProfessionalDriver implements MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung gesamt', 'F', 'MHB.W',   true,  'total',  'FC3 4527'],
            ['voltage_avg',   'Spannung Ø',          'F', 'MHB.V',   false, 'total',  'FC3 4567/68/69 Ø'],
            ['frequency',     'Frequenz',            'F', 'MHB.Hz',  false, 'total',  'FC3 4626 (×0,1)'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'MHB.kWh', true,  'energy', 'FC3 4201 (×0,001)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'MHB.kWh', true,  'energy', 'FC3 4281 (×0,001)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase', 'vars' => [
                ['u_l1_n', 'Spannung L1', 'F', 'MHB.V', false, 'voltage', 'FC3 4567 (×0,1)'],
                ['u_l2_n', 'Spannung L2', 'F', 'MHB.V', false, 'voltage', 'FC3 4568 (×0,1)'],
                ['u_l3_n', 'Spannung L3', 'F', 'MHB.V', false, 'voltage', 'FC3 4569 (×0,1)'],
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
// ShellyPro3emDriver — Shelly Pro 3EM
// FC 0x04 (Input Register), Float32 mit GETAUSCHTER Wortreihenfolge (CDAB).
// Wire-Adresse = Doku-Registernummer − 30000: EM-Messwerte ab 1011
// (Gesamtleistung 1013, Frequenz 1033, Phasen 1020/40/60 …), EMData-Energie
// 1162 (Bezug) / 1164 (Einspeisung) in Wh. An echtem Shelly Pro 3EM verifiziert.
// Modbus TCP muss am Gerät aktiviert sein (Einstellungen → Modbus, tcp/502).
// ---------------------------------------------------------------------------

class ShellyPro3emDriver implements MeterDriverInterface
{
    public function getBaseVars()
    {
        return [
            ['power_total',   'Wirkleistung gesamt', 'F', 'MHB.W',   true,  'total',  'FC4 1013'],
            ['voltage_avg',   'Spannung Ø',          'F', 'MHB.V',   false, 'total',  'FC4 1020/40/60 Ø'],
            ['current_avg',   'Strom Ø',             'F', 'MHB.A',   false, 'total',  'FC4 1022/42/62 Ø'],
            ['frequency',     'Frequenz',            'F', 'MHB.Hz',  false, 'total',  'FC4 1033'],
            ['energy_import', 'Wirkarbeit Bezug',    'F', 'MHB.kWh', true,  'energy', 'FC4 1162 (Wh)'],
            ['energy_export', 'Wirkarbeit Abgabe',   'F', 'MHB.kWh', true,  'energy', 'FC4 1164 (Wh)'],
            ['connected',     'Verbindung',          'B', '~Alert.Reversed', false, 'errors', ''],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupVoltagePhase' => ['caption' => 'Spannung je Phase', 'vars' => [
                ['u_l1_n', 'Spannung L1', 'F', 'MHB.V', false, 'voltage', 'FC4 1020'],
                ['u_l2_n', 'Spannung L2', 'F', 'MHB.V', false, 'voltage', 'FC4 1040'],
                ['u_l3_n', 'Spannung L3', 'F', 'MHB.V', false, 'voltage', 'FC4 1060'],
            ]],
            'GroupCurrentPhase' => ['caption' => 'Strom je Phase', 'vars' => [
                ['i_l1', 'Strom L1', 'F', 'MHB.A', false, 'current', 'FC4 1022'],
                ['i_l2', 'Strom L2', 'F', 'MHB.A', false, 'current', 'FC4 1042'],
                ['i_l3', 'Strom L3', 'F', 'MHB.A', false, 'current', 'FC4 1062'],
            ]],
            'GroupPowerPhase' => ['caption' => 'Wirkleistung je Phase', 'vars' => [
                ['p_l1', 'Wirkleistung L1', 'F', 'MHB.W', false, 'power', 'FC4 1024'],
                ['p_l2', 'Wirkleistung L2', 'F', 'MHB.W', false, 'power', 'FC4 1044'],
                ['p_l3', 'Wirkleistung L3', 'F', 'MHB.W', false, 'power', 'FC4 1064'],
            ]],
            // Eigene Energiezähler je Phase — damit lässt sich jede Phase als
            // eigenständiger Verbraucher führen (Summe der drei = Gesamtzähler).
            'GroupEnergyPhase' => ['caption' => 'Energie je Phase (Bezug/Abgabe)', 'vars' => [
                ['energy_import_l1', 'Wirkarbeit Bezug L1',  'F', 'MHB.kWh', true, 'energy', 'FC4 1182 (Wh)'],
                ['energy_export_l1', 'Wirkarbeit Abgabe L1', 'F', 'MHB.kWh', true, 'energy', 'FC4 1184 (Wh)'],
                ['energy_import_l2', 'Wirkarbeit Bezug L2',  'F', 'MHB.kWh', true, 'energy', 'FC4 1202 (Wh)'],
                ['energy_export_l2', 'Wirkarbeit Abgabe L2', 'F', 'MHB.kWh', true, 'energy', 'FC4 1204 (Wh)'],
                ['energy_import_l3', 'Wirkarbeit Bezug L3',  'F', 'MHB.kWh', true, 'energy', 'FC4 1222 (Wh)'],
                ['energy_export_l3', 'Wirkarbeit Abgabe L3', 'F', 'MHB.kWh', true, 'energy', 'FC4 1224 (Wh)'],
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
// MeterHub — Hauptmodul, lädt den Treiber laut Meter-Property
// ---------------------------------------------------------------------------

class MeterHub extends IPSModule
{
    private const DRIVERS = [
        'siemens_pac2200' => 'Pac2200Driver',
        'janitza_umg604'  => 'JanitzaClassicDriver',
        'janitza_umg605'  => 'JanitzaClassicDriver',
        'janitza_umg509'  => 'JanitzaClassicDriver',
        'janitza_umg512'  => 'JanitzaClassicDriver',
        'janitza_umg806'  => 'JanitzaClassicDriver',
        'janitza_umg96pa' => 'JanitzaClassicDriver',
        'janitza_umg801'  => 'JanitzaClassicDriver',
        'janitza_umg800'  => 'Umg800Driver',
        'eastron_sdm72d'  => 'EastronSdmDriver',
        'eastron_sdm630'  => 'EastronSdmDriver',
        'whatwatt'        => 'WhatWattDriver',
        'phoenix_eem375'  => 'PhoenixEem375Driver',
        'phoenix_eemxm'   => 'PhoenixEemXmDriver',
        'carlo_gavazzi_em' => 'CarloGavazziDriver',
        'socomec_countis'  => 'SocomecCountisDriver',
        'mbs_professional' => 'MbsProfessionalDriver',
        'shelly_pro3em'    => 'ShellyPro3emDriver',
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
    ];

    // Funktions-Vokabular für die Zuordnung „welcher Verbraucher hängt hier?".
    // [Schlüssel => [Anzeigename, IPS-Icon]]. Bewusst an den Verbraucher-Arten
    // der InverterHubTile-Kachel orientiert, damit andere Module die Zuordnung
    // direkt übernehmen können.
    private const FUNCTIONS = [
        'none'      => ['— keine Zuordnung —',    ''],
        'grid'      => ['Netzanschluss',          'Electricity'],
        'house'     => ['Hausverbrauch',          'HollowHouse'],
        'pv'        => ['PV-Erzeugung',           'Sun'],
        'battery'   => ['Batterie',               'Battery'],
        'heatpump'  => ['Wärmepumpe',             'Temperature'],
        'wallbox1'  => ['Wallbox 1',              'Car'],
        'wallbox2'  => ['Wallbox 2',              'Car'],
        'wallbox3'  => ['Wallbox 3',              'Car'],
        'wallbox4'  => ['Wallbox 4',              'Car'],
        'wallbox5'  => ['Wallbox 5',              'Car'],
        'garage'    => ['Garage',                 'Car'],
        'hotwater'  => ['Warmwasser',             'Drops'],
        'aircon'    => ['Klimaanlage',            'Snowflake'],
        'pool'      => ['Pool',                   'Waves'],
        'sauna'     => ['Sauna',                  'Flame'],
        'dryer'     => ['Trockner',               'Wind'],
        'kitchen'   => ['Küche',                  'Gear'],
        'light'     => ['Beleuchtung',            'Bulb'],
        'other'     => ['Sonstiger Verbraucher',  'Electricity'],
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

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitId', 1);
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

        if (!$this->ReadPropertyBoolean('Active') || $this->ReadPropertyString('Host') === '') {
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
        $ok = $this->GetDriver()->readFast($this->GetModbusClient(), $this);
        $this->SetStatus($ok ? 102 : 201);
        $this->UpdateMirrors();
    }

    public function ReadSlow()
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $this->GetDriver()->readSlow($this->GetModbusClient(), $this);
        $this->UpdateMirrors();
    }

    public function GetConfigurationForm()
    {
        $driver = $this->GetDriver();

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

        $form = [
            'elements' => [
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖  Dokumentation & Hilfe',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'MeterHub liest Energiezähler verschiedener Hersteller direkt per Modbus TCP aus. Zählertyp wählen, IP-Adresse (und ggf. Port/Unit-ID) eintragen, Datenpunkt-Gruppen je nach Bedarf aktivieren.'],
                        ['type' => 'Label', 'caption' => 'Unterstützte Zähler: Siemens SENTRON PAC2200 (FC 0x03); Janitza-UMG-Reihe (UMG 604/605/509/512/806/96PA/801 klassische Karte, UMG 800 Werkskarte, FC 0x03); Eastron SDM72D-M v2, WhatWatt und Phoenix Contact EEM-EM375/EEM-XM (FC 0x04, Input-Register).'],
                        ['type' => 'Label', 'caption' => 'Hinweis Eastron/Phoenix: Diese sprechen meist Modbus RTU und hängen über einen RTU/TCP-Gateway (dessen IP eintragen). Eastron-Geräteadresse ab Werk 1; Phoenix EEM-EM375 nutzt oft Unit-ID 255, EEM-XM meist 1. WhatWatt spricht Modbus TCP direkt.'],
                        ['type' => 'Label', 'caption' => '🧪 Experimentell: Socomec Countis und MBS Professional 3-75 sind aus Vorlagen abgeleitet und noch nicht an echter Hardware geprüft — bitte die Messwerte gegen die Geräteanzeige abgleichen. Bei unplausiblen Werten helfen der WordSwap- bzw. Invers-Schalter.'],
                        ['type' => 'Label', 'caption' => '🔌 Shelly Pro 3EM: Modbus TCP muss am Gerät erst aktiviert werden (Einstellungen → Modbus, Port 502). Gelesen über FC 0x04, Float wortgetauscht (CDAB); Wire-Adressen = Doku − 30000 (Messwerte ab 1011, Energie 1162/1164). An echtem Gerät verifiziert.'],
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
                [
                    'type'    => 'Select',
                    'name'    => 'Meter',
                    'caption' => 'Zählertyp',
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
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🏷️  Funktionszuordnung',
                    'expanded' => false,
                    'items'    => $funcItems,
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔌  Verbindung',
                    'expanded' => true,
                    'items' => [
                        ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse', 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'NumberSpinner', 'name' => 'UnitId', 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
                    ],
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
                ['type' => 'Button', 'caption' => 'Verbindung testen / Daten sofort lesen', 'onClick' => 'MHUB_ReadFast($id); MHUB_ReadSlow($id);'],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte IP-Adresse eintragen.'],
                ['code' => 102, 'icon' => 'active',   'caption' => 'Verbindung aktiv.'],
                ['code' => 201, 'icon' => 'error',    'caption' => 'Verbindungsfehler – Zähler nicht erreichbar.'],
            ],
        ];

        return json_encode($form);
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

    private function GetModbusClient(): ModbusTcpClient
    {
        $mb = new ModbusTcpClient(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyInteger('UnitId')
        );
        $mb->setWordSwap($this->ReadPropertyBoolean('WordSwap'));
        return $mb;
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
            $defs[] = ['fn_' . $slug . '_power',  $a['label'] . ' — Leistung',       'F', 'MHB.W',   true, 'function', $a['icon'], $a['power']];
            $defs[] = ['fn_' . $slug . '_import', $a['label'] . ' — Bezug',          'F', 'MHB.kWh', true, 'function', $a['icon'], $a['import']];
            $defs[] = ['fn_' . $slug . '_export', $a['label'] . ' — Einspeisung',    'F', 'MHB.kWh', true, 'function', $a['icon'], $a['export']];
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

    /**
     * Öffentliche Abfrage der Zuordnung für andere Module (EMS, Kacheln):
     * liefert JSON mit Modus und je Zuordnung Funktion, Bezeichnung und den
     * Variablen-IDs für Leistung, Bezug und Einspeisung.
     */
    public function GetFunctions(): string
    {
        $list = [];
        foreach ($this->FunctionAssignments() as $a) {
            $list[] = [
                'slot'            => $a['slot'],
                'function'        => $a['key'],
                'label'           => $a['label'],
                'powerID'         => $this->FindVarByIdent($a['power']),
                'energyImportID'  => $this->FindVarByIdent($a['import']),
                'energyExportID'  => $this->FindVarByIdent($a['export']),
            ];
        }
        return json_encode([
            'instanceID'  => $this->InstanceID,
            'meter'       => $this->ReadPropertyString('Meter'),
            'measureMode' => $this->ReadPropertyString('MeasureMode'),
            'assignments' => $list,
        ]);
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

    private function RegisterVar(array $def, int $pos)
    {
        [$ident, $caption, $type, $profile, $archive, $group] = $def;
        $reg = isset($def[6]) ? $def[6] : '';

        $vtype = [
            'F' => VARIABLETYPE_FLOAT,
            'I' => VARIABLETYPE_INTEGER,
            'B' => VARIABLETYPE_BOOLEAN,
            'S' => VARIABLETYPE_STRING,
        ][$type];

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
        if ($profile === 'MHB.kWh' && $this->ReadPropertyBoolean('EnergyUnitWh')) {
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
        // Gemeinsame MeterHub-Profile (für beide Treiber).
        $this->ensureProfile('MHB.V',       ' V',    1, 'Electricity');
        $this->ensureProfile('MHB.A',       ' A',    1, 'Electricity');
        $this->ensureProfile('MHB.W',       ' W',    0, 'Electricity');
        $this->ensureProfile('MHB.VA',      ' VA',   0, '');
        $this->ensureProfile('MHB.var',     ' var',  0, '');
        $this->ensureProfile('MHB.Hz',      ' Hz',   2, '');
        $this->ensureProfile('MHB.kWh',     ' kWh',  1, 'Electricity');
        $this->ensureProfile('MHB.Wh',      ' Wh',   0, 'Electricity');
        $this->ensureProfile('MHB.PF',      '',      2, '');
        $this->ensureProfile('MHB.Percent', ' %',    1, '');

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
}
