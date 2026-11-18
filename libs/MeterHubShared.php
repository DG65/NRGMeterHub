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
    // Was die einbindende Klasse beisteuert: ihre Übersetzung, das Präfix ihrer öffentlichen Funktionen (MHUB/MHUBV/MHUBD) und
    // die GUID der Geschwister-Instanzen, über die das Ausblenden geteilt wird.
    abstract private function txt(string $text, ...$args): string;

    abstract private function fnPrefix(): string;

    abstract private function siblingModuleGuid(): string;


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

    /** Name eines Fachpanels für die Liste im Aufbau-Panel (ohne Klammerzusatz). */
    private function layoutPanelName(array $panel, string $key): string
    {
        // Der Name steht als Wert in einer Liste (nicht als Beschriftung) — die Konsole übersetzt ihn nicht, deshalb hier.
        return trim((string)preg_replace('/\s*\(.*$/u', '', $this->txt((string)($panel['caption'] ?? $key))));
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
            ? [['type' => 'Label', 'caption' => $this->txt('🙈 Hidden: %s — to show again, clear the "Hidden" checkbox. Hidden sections keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => $this->txt('🪟  Form: layout%s', $hidden ? $this->txt(' (%s hidden)', count($hidden)) : ''),
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
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo ' . $this->fnPrefix() . '_ResetFormLayout($id);'],
            ]),
        ];
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
        foreach (IPS_GetInstanceListByModuleID($this->siblingModuleGuid()) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                ($this->fnPrefix() . '_AdoptDismissState')($sib, $what, $value);
            } catch (\Throwable $e) {
                // Eine Geschwister-Instanz mitten im Reload/Löschen darf das
                // Ausblenden der aufrufenden Instanz nicht mitreißen — @ hält
                // Fatals bekanntlich nicht auf (siehe CLAUDE.md, MigrationsHub-
                // Brücke).
            }
        }
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
        foreach (IPS_GetInstanceListByModuleID($this->siblingModuleGuid()) as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                $state = ($this->fnPrefix() . '_GetDismissState')($sib);
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
}
