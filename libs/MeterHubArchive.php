<?php

// ===========================================================================
// MeterHub — Archiv: Zählerschutz gegen Null-/Rückwärtswerte, Energie-Archiv prüfen/reparieren, Richtung im Archiv prüfen/
// korrigieren (PowerInvert-Altlasten), Live-Zwischenwerte bereinigen, Diagnose/Rollenprüfung und die Hilfen für Zählerpaare.
//
// Ausgelagert aus MeterHub/module.php (0.39.5, Umbau 2 der Code-Bestandsaufnahme vom 18.11.2026): die Methoden stehen WÖRTLICH so
// da wie vorher in der Klasse MeterHub, die diesen Trait einbindet (`use MHUB_ArchiveRepair;`). Öffentliche Funktionen
// (MHUB_CheckEnergyArchive() usw.) bleiben in der Klasse.
// ===========================================================================

trait MHUB_ArchiveRepair
{
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
            $sign = $this->PowerSign();
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
        } elseif ($this->IsCloudMeter()) {
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
            if ($this->IsCloudMeter()) {
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

    private function InexogyCleanup(int $days, bool $apply): string
    {
        if (!$this->IsCloudMeter()) {
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
}
