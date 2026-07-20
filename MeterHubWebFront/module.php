<?php

// ---------------------------------------------------------------------------
// MeterHubWebFront — Kachel, die das Web-Frontend eines Zählers (Siemens
// PAC2200, Janitza UMG) per iframe einbettet. Die IP kommt wahlweise aus einer
// verknüpften MeterHub-Instanz oder wird manuell eingetragen; Protokoll/Port/
// Pfad beziehen sich auf die WEBOBERFLÄCHE des Geräts (nicht auf Modbus 502).
// ---------------------------------------------------------------------------

class MeterHubWebFront extends IPSModule
{
    private const METERHUB_GUID = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('SourceInstance', 0);
        $this->RegisterPropertyString('ManualHost', '');
        $this->RegisterPropertyString('Protocol', 'http');
        $this->RegisterPropertyInteger('WebPort', 80);
        $this->RegisterPropertyString('Path', '/');
        $this->RegisterPropertyString('Title', '');
        $this->RegisterPropertyInteger('Zoom', 100);
        $this->RegisterPropertyInteger('RefreshSec', 0);
        $this->RegisterPropertyBoolean('ShowToolbar', true);

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Bei Änderungen die geöffnete Kachel neu aufbauen lassen.
        $this->ReloadForm();
    }

    public function GetConfigurationForm()
    {
        return file_get_contents(__DIR__ . '/form.json');
    }

    // Host aus manueller Eingabe (Vorrang) oder aus der verknüpften
    // MeterHub-Instanz (deren Modbus-Host).
    private function ResolveHost(): string
    {
        $manual = trim($this->ReadPropertyString('ManualHost'));
        if ($manual !== '') {
            return $manual;
        }
        $src = $this->ReadPropertyInteger('SourceInstance');
        if ($src > 0 && @IPS_InstanceExists($src)) {
            $h = @IPS_GetProperty($src, 'Host');
            if (is_string($h) && $h !== '') {
                return $h;
            }
        }
        return '';
    }

    // Setzt die vollständige URL zur Weboberfläche zusammen.
    public function GetURL(): string
    {
        $host = $this->ResolveHost();
        if ($host === '') {
            return '';
        }
        $proto = ($this->ReadPropertyString('Protocol') === 'https') ? 'https' : 'http';
        $port  = (int)$this->ReadPropertyInteger('WebPort');
        $path  = $this->ReadPropertyString('Path');
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $defaultPort = ($proto === 'https') ? 443 : 80;
        $portPart = ($port > 0 && $port !== $defaultPort) ? (':' . $port) : '';
        return $proto . '://' . $host . $portPart . $path;
    }

    // Button in der Konfiguration: aufgelöste URL anzeigen (zum Prüfen).
    public function ShowResolvedURL()
    {
        $url = $this->GetURL();
        echo $url !== '' ? $url : 'Keine IP/Host konfiguriert.';
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $payload = [
            'url'         => $this->GetURL(),
            'title'       => trim($this->ReadPropertyString('Title')),
            'zoom'        => max(25, min(200, (int)$this->ReadPropertyInteger('Zoom'))) / 100.0,
            'refreshMs'   => max(0, min(3600, (int)$this->ReadPropertyInteger('RefreshSec'))) * 1000,
            'showToolbar' => $this->ReadPropertyBoolean('ShowToolbar'),
        ];
        $html .= '<script>handleMessage(' . json_encode($payload) . ');</script>';
        return $html;
    }
}
