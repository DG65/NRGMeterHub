<?php

// ===========================================================================
// MeterHub — Inexogy-/Discovergy-Cloudzähler: Anmeldung (OAuth-Handshake, automatische Neuanmeldung über einen Tresor),
// Archiv-Nachtrag der 15-Minuten-Werte und dessen Wasserstand.
//
// Ausgelagert aus MeterHub/module.php (0.39.5, Umbau 2 der Code-Bestandsaufnahme vom 18.11.2026): die Methoden stehen WÖRTLICH so
// da wie vorher in der Klasse MeterHub, die diesen Trait einbindet (`use MHUB_InexogyCloud;`). Nichts davon ist ein eigenes Modul;
// die Funktionen greifen weiter auf $this (Eigenschaften, Attribute, Hilfsfunktionen) der Instanz zu. Öffentliche Funktionen
// (MHUB_InexogyLogin() usw.) bleiben in der Klasse.
// ===========================================================================

trait MHUB_InexogyCloud
{
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
        if (!$this->IsCloudMeter()) {
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
        if (!$this->IsCloudMeter()) {
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
            // Seit 0.34.0 schaltet MeterHub das Logging nie selbst ein. Für eine nicht
            // archivierte Variable wirft AC_GetLoggedValues() eine Warnung, die im
            // Konfigurationsformular als Text vor dem JSON landet ("Konnte
            // Konfigurationsform nicht laden", Horst/hbraun 15.11.2026). Ohne Logging
            // gibt es keinen Archivstand: Wasserstand 0 = "noch nichts archiviert".
            if (!function_exists('AC_GetLoggingStatus') || !@AC_GetLoggingStatus($archiveID, $vid)) {
                $watermark = 0;
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
        if (!$this->IsCloudMeter()) {
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
        if (!$this->IsCloudMeter()) {
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
        //
        // PowerInvert (0.38.6, Befund hbraun 17.11.2026): der Live-Pfad leitet
        // das Bezugsregister bei gesetztem Haken in die andere Energie-Variable
        // um (EnergyIdentForInvert()) und dreht die Leistung (SetVarFloat()).
        // Der Nachtrag schrieb bisher ohne beides — Bezug und Abgabe landeten in
        // der jeweils falschen Variable, die Leistung mit umgekehrtem Vorzeichen,
        // und Live- und Nachtragswerte mischten sich zu Sprungreihen. Deshalb
        // dasselbe Ziel und dasselbe Vorzeichen wie der Live-Pfad.
        $fields = [
            'energy'    => ['ident' => $this->EnergyIdentForInvert('energy_import'), 'scale' => 1e10],
            'energyOut' => ['ident' => $this->EnergyIdentForInvert('energy_export'), 'scale' => 1e10],
            'power'     => ['ident' => 'power_total', 'scale' => 1000.0 * $this->PowerSign()],
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
}
