<?php

declare(strict_types=1);

class RoonZone extends IPSModule
{
    public function Create()
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RegisterPropertyString('ZoneName', '');

        // Verbinde mit dem MQTT Client (Parent)
        $this->RequireParent('{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}');

        // Profile anlegen
        if (!IPS_VariableProfileExists('ROON.State')) {
            IPS_CreateVariableProfile('ROON.State', 1);
            IPS_SetVariableProfileAssociation('ROON.State', 0, 'Stopped', 'MediaStop', 0xFF0000);
            IPS_SetVariableProfileAssociation('ROON.State', 1, 'Playing', 'MediaPlay', 0x00FF00);
            IPS_SetVariableProfileAssociation('ROON.State', 2, 'Paused', 'MediaPause', 0xFFFF00);
            IPS_SetVariableProfileAssociation('ROON.State', 3, 'Loading', 'Refresh', 0x0000FF);
        }

        if (!IPS_VariableProfileExists('ROON.Volume')) {
            IPS_CreateVariableProfile('ROON.Volume', 1);
            IPS_SetVariableProfileValues('ROON.Volume', 0, 100, 1);
            IPS_SetVariableProfileText('ROON.Volume', '', ' %');
            IPS_SetVariableProfileIcon('ROON.Volume', 'Intensity');
        }

        // Variablen registrieren
        $this->RegisterVariableInteger('State', 'Status', 'ROON.State', 1);
        $this->RegisterVariableString('Title', 'Titel', '', 2);
        $this->RegisterVariableString('Artist', 'Künstler', '', 3);
        $this->RegisterVariableString('Album', 'Album', '', 4);
        $this->RegisterVariableInteger('Volume', 'Lautstärke', 'ROON.Volume', 5);

        // Aktionen für die Bedienung freigeben
        $this->EnableAction('State');
        $this->EnableAction('Volume');
    }

    public function ApplyChanges()
    {
        // Diese Zeile nicht löschen
        parent::ApplyChanges();

        $zone = $this->ReadPropertyString('ZoneName');
        if (empty($zone)) {
            $this->SetStatus(104); // IS_INACTIVE
            return;
        }

        $this->SetStatus(102); // IS_ACTIVE

        // Formatiere Zone Name für MQTT Topic (Leerzeichen zu Bindestrich etc.)
        $topicZone = $this->GetMqttZoneName($zone);

        // Filter setzen: Wir wollen alle roon/zonename/# Topics empfangen
        $this->SetReceiveDataFilter('.*roon/' . $topicZone . '.*');
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['Topic']) || !isset($data['Payload'])) {
            return;
        }

        $topic = $data['Topic'];
        $payload = $data['Payload'];

        $topicZone = $this->GetMqttZoneName($this->ReadPropertyString('ZoneName'));

        // Titel
        if ($topic === 'roon/' . $topicZone . '/now_playing/three_line/line1') {
            $this->SetValue('Title', $payload);
        }
        // Künstler
        elseif ($topic === 'roon/' . $topicZone . '/now_playing/three_line/line2') {
            $this->SetValue('Artist', $payload);
        }
        // Album
        elseif ($topic === 'roon/' . $topicZone . '/now_playing/three_line/line3') {
            $this->SetValue('Album', $payload);
        }
        // Status
        elseif ($topic === 'roon/' . $topicZone . '/state') {
            switch (strtolower($payload)) {
                case 'stopped':
                    $this->SetValue('State', 0);
                    break;
                case 'playing':
                    $this->SetValue('State', 1);
                    break;
                case 'paused':
                    $this->SetValue('State', 2);
                    break;
                case 'loading':
                    $this->SetValue('State', 3);
                    break;
            }
        }
        // Lautstärke
        elseif ($topic === 'roon/' . $topicZone . '/volume/value') {
            $this->SetValue('Volume', intval($payload));
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'State':
                if ($Value == 0) {
                    $this->SendCommand('stop');
                } elseif ($Value == 1) {
                    $this->SendCommand('play');
                } elseif ($Value == 2) {
                    $this->SendCommand('pause');
                }
                break;

            case 'Volume':
                $this->SetVolume($Value);
                break;
        }
    }

    public function SendCommand(string $command)
    {
        $topicZone = $this->GetMqttZoneName($this->ReadPropertyString('ZoneName'));
        $topic = 'roon/' . $topicZone . '/command';
        $this->PublishMqtt($topic, $command);
    }

    public function SetVolume(int $volume)
    {
        $topicZone = $this->GetMqttZoneName($this->ReadPropertyString('ZoneName'));
        $topic = 'roon/' . $topicZone . '/volume/set';
        $this->PublishMqtt($topic, (string)$volume);
    }

    public function TogglePlayPause()
    {
        $this->SendCommand('playpause');
    }

    public function NextTrack()
    {
        $this->SendCommand('next');
    }

    public function PreviousTrack()
    {
        $this->SendCommand('previous');
    }

    private function PublishMqtt(string $topic, string $payload)
    {
        if (!$this->HasActiveParent()) {
            IPS_LogMessage('RoonZone', 'No active MQTT parent');
            return;
        }

        $data = [
            'DataID'  => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType' => 3,
            'QualityOfService' => 0,
            'Retain'  => false,
            'Topic'   => $topic,
            'Payload' => $payload
        ];

        $this->SendDataToParent(json_encode($data));
    }

    private function GetMqttZoneName(string $zoneName): string
    {
        // roon-extension-mqtt ersetzt bestimmte Sonderzeichen mit Bindestrichen
        $search = [' ', '+', '/', '#'];
        return str_replace($search, '-', $zoneName);
    }
}
