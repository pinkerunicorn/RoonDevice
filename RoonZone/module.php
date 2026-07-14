<?php

declare(strict_types=1);

class RoonZone extends IPSModuleStrict
{
    public function Create(): void
    {
        // Diese Zeile nicht löschen.
        parent::Create();

        $this->RegisterPropertyString('ZoneName', '');

        // Verbinde mit dem MQTT Client/Server (Parent)
        // (Wird automatisch durch parentRequirements in module.json uebernommen)

        // Variablen registrieren

        
        $this->RegisterVariableInteger('State', 'ℹ Status', '', 1);
        $this->RegisterVariableString('Title', '🎵 Titel', '', 2);
        $this->RegisterVariableString('Artist', '🎤 Künstler', '', 3);
        $this->RegisterVariableString('Album', '💿 Album', '', 4);
        $this->RegisterVariableInteger('Volume', '🔊 Lautstärke', '', 5);

        // Aktionen für die Bedienung freigeben
        $this->EnableAction('State');
        $this->EnableAction('Volume');
    }

    public function ApplyChanges(): void
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

        // Filter setzen: Da json_encode oft Slashes als \/ escaped, nehmen wir einen toleranteren Filter
        $this->SetReceiveDataFilter('.*'. $topicZone . '.*');

        
        if (!IPS_VariableProfileExists('Roon.State')) {
            IPS_CreateVariableProfile('Roon.State', 1);
            IPS_SetVariableProfileAssociation('Roon.State', 0, 'Previous', '', -1);
            IPS_SetVariableProfileAssociation('Roon.State', 1, 'Stop', '', -1);
            IPS_SetVariableProfileAssociation('Roon.State', 2, 'Play', '', -1);
            IPS_SetVariableProfileAssociation('Roon.State', 3, 'Pause', '', -1);
            IPS_SetVariableProfileAssociation('Roon.State', 4, 'Next', '', -1);
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent('State'), 'Roon.State');
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Volume'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SLIDER,
            'MIN'=> 0,
            'MAX'=> 100,
            'STEP'=> 1,
            'SUFFIX'=> '%'
        ]);
    }

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!isset($data['Topic']) || !isset($data['Payload'])) {
            return "";
        }

        $topic = (string) $data['Topic'];
        $payloadRaw = is_scalar($data['Payload']) ? (string) $data['Payload'] : '';
        $payload = (ctype_xdigit($payloadRaw) || empty($payloadRaw)) ? hex2bin($payloadRaw) : $payloadRaw;

        // IPS_LogMessage('SmartVillaKunterbunt', 'RoonZone: '. 'Received Topic: '. $topic . '| Payload: '. $payload);

        $topicZone = $this->GetMqttZoneName($this->ReadPropertyString('ZoneName'));

        // Titel
        if ($topic === 'roon/'. $topicZone . '/now_playing/three_line/line1') {
            $this->SetValue('Title', $payload);
        }
        // Künstler
        elseif ($topic === 'roon/'. $topicZone . '/now_playing/three_line/line2') {
            $this->SetValue('Artist', $payload);
        }
        // Album
        elseif ($topic === 'roon/'. $topicZone . '/now_playing/three_line/line3') {
            $this->SetValue('Album', $payload);
        }
        // Status
        elseif ($topic === 'roon/'. $topicZone . '/state') {
            switch (strtolower($payload)) {
                case 'stopped':
                    $this->SetValue('State', 1); // 1 = Stop
                    break;
                case 'playing':
                    $this->SetValue('State', 2); // 2 = Play
                    break;
                case 'paused':
                    $this->SetValue('State', 3); // 3 = Pause
                    break;
                case 'loading':
                    $this->SetValue('State', 1); // Fallback zu Stop
                    break;
            }
        }
        // Lautstärke: roon/zonename/outputs/outputname/volume/value
        if (preg_match('/^roon\/'. preg_quote($topicZone, '/') . '\/outputs\/(.+)\/volume\/value$/', $topic, $matches)) {
            $outputName = $matches[1];
            // Speichere den Output-Namen für spätere Kommandos
            $this->SetBuffer('OutputName', $outputName);
            
            // Konvertiere dB (-60 bis 0) in % (0 bis 100) für das ~Volume Profil
            $db = (int) $payload;
            $db = max(-60, min(0, $db));
            $percent = (int) round(($db + 60) * 100 / 60);
            $this->SetValue('Volume', $percent);
        }
        return "";
    }

    public function RequestAction(string $Ident, $Value): void
    {
        switch ($Ident) {
            case 'State':
                if ($Value == 0) {
                    $this->SendCommand('previous');
                } elseif ($Value == 1) {
                    $this->SendCommand('stop');
                } elseif ($Value == 2) {
                    $this->SendCommand('play');
                } elseif ($Value == 3) {
                    $this->SendCommand('pause');
                } elseif ($Value == 4) {
                    $this->SendCommand('next');
                }
                break;

            case 'Volume':
                $outputName = $this->GetBuffer('OutputName');
                if ($outputName !== '') {
                    // Konvertiere % (0 bis 100) in dB (-60 bis 0)
                    $percent = max(0, min(100, (int) $Value));
                    $db = (int) round(($percent * 60 / 100) - 60);
                    $this->SendMQTTVolumeCommand($outputName, 'set', (string)$db);
                }
                break;
        }
    }

    private function SendMQTTCommand(string $command, string $payload = ''): void
    {
        $topicZone = $this->GetMqttZoneName($this->ReadPropertyString('ZoneName'));
        $topic = 'roon/'. $topicZone . '/command';

        $this->PublishMqtt($topic, $command); // Usually command is the payload itself for simple actions
    }

    private function SendMQTTVolumeCommand(string $outputName, string $command, string $payload): void
    {
        $topicZone = $this->GetMqttZoneName($this->ReadPropertyString('ZoneName'));
        $topic = 'roon/'. $topicZone . '/outputs/'. $outputName . '/volume/'. $command;

        $this->PublishMqtt($topic, (string)$payload);
    }

    public function SendCommand(string $command): void
    {
        $topicZone = $this->GetMqttZoneName($this->ReadPropertyString('ZoneName'));
        $topic = 'roon/'. $topicZone . '/command';
        $this->PublishMqtt($topic, $command);
    }

    public function SetVolume(int $volume): void
    {
        $topicZone = $this->GetMqttZoneName($this->ReadPropertyString('ZoneName'));
        $topic = 'roon/'. $topicZone . '/volume/set';
        $this->PublishMqtt($topic, (string)$volume);
    }

    public function TogglePlayPause(): void
    {
        $this->SendCommand('playpause');
    }

    public function NextTrack(): void
    {
        $this->SendCommand('next');
    }

    public function PreviousTrack(): void
    {
        $this->SendCommand('previous');
    }

    private function PublishMqtt(string $topic, string $payload): void
    {
        if (!$this->HasActiveParent()) {
            IPS_LogMessage('SmartVillaKunterbunt', 'RoonZone: '. 'No active MQTT parent');
            return;
        }

        $data = [
            'DataID' => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'=> 3,
            'QualityOfService'=> 0,
            'Retain' => false,
            'Topic'  => $topic,
            'Payload'=> bin2hex($payload)
        ];

        $this->SendDataToParent(json_encode($data));
    }

    private function GetMqttZoneName(string $zoneName): string
    {
        // Den exakten Zonen-Namen zurueckgeben, da roon-extension-mqtt scheinbar doch Leerzeichen zulaesst
        return $zoneName;
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        IPS_LogMessage('SmartVillaKunterbunt', 'RoonZone: '. $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "ValidationTextBox",
                    "name": "ZoneName",
                    "caption": "Roon Zone Name (exakte Schreibweise aus Roon)"
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Button",
            "label": "Play / Pause umschalten",
            "onClick": "ROON_TogglePlayPause($id);"
        }
    ],
    "status": [
        {
            "code": 102,
            "icon": "active",
            "caption": "Zone ist konfiguriert."
        },
        {
            "code": 104,
            "icon": "inactive",
            "caption": "Kein Zonenname konfiguriert."
        }
    ]
}
EOT;
    }
}


