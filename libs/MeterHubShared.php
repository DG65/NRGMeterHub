<?php

// ===========================================================================
// MeterHub — gemeinsame Bausteine mehrerer Module und Klassen der Bibliothek (0.40.0, Umbau 3 der Code-Bestandsaufnahme vom 18.11.2026).
//
// Diese Methoden standen bisher Zeichen für Zeichen gleich in mehreren Klassen und werden jetzt von dort eingebunden:
//   MHUB_ModbusCodec   — Registerdekodierung der beiden Modbus-Clients (direkt und Gateway) in MeterHub/module.php
//   MHUB_SharedForm    — Aufbau der Fachpanels (formLayout, ApplyFormLayout) und Lizenzhinweis: MeterHub, MeterHubVirtual, MeterHubDiscovery
//   MHUB_SharedInstance — Bereitschaftsprüfung, Archiv-Instanz, Verdichtungsmerker und -auswahl: MeterHub, MeterHubVirtual
// Es sind Traits, kein eigenes Modul. Die Methoden stehen wörtlich so da wie vorher; sie greifen weiter auf $this und self:: der
// einbindenden Klasse zu (z. B. LICENSE_URL). Öffentliche Funktionen (MHUB_AckNews() usw.) bleiben bewusst in den Klassen.
// Wer hier etwas ändert, ändert es für alle einbindenden Klassen. Eine Abweichung eines einzelnen Moduls gehört NICHT hierher.
// ===========================================================================

/** Registerdekodierung der Modbus-Clients; die einbindende Klasse hat die Eigenschaft $wordSwap und setWordSwap(). */
trait MHUB_ModbusCodec
{
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
}

/** Aufbau der Fachpanels und Lizenzhinweis; die einbindende Klasse hat die Konstanten LICENSE_URL und PAYPAL_URL. */
trait MHUB_SharedForm
{
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
}

/** Bausteine für Module mit Archiv-Verdichtung (MeterHub, MeterHubVirtual). */
trait MHUB_SharedInstance
{
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
}
