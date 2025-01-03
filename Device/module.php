<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/ModulBase.php';

class Zigbee2MQTTDevice extends \Zigbee2MQTT\ModulBase
{
    /** @var mixed $ExtensionTopic Topic für den ReceiveFilter*/
    protected static $ExtensionTopic = 'getDeviceInfo/';

    /**
     * Create
     *
     * @return void
     */
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('IEEE', '');
        $this->RegisterAttributeString('IEEE', '');
        $this->RegisterAttributeString('Model', '');
        $this->RegisterAttributeString('Icon', '');
        $this->RegisterMessage($this->InstanceID, IM_CHANGEATTRIBUTE);
    }

    /**
     * ApplyChanges
     *
     * @return void
     */
    public function ApplyChanges()
    {
        // Führe parent::ApplyChanges zuerst aus
        parent::ApplyChanges();

        $ieee = $this->ReadPropertyString('IEEE');
        $this->SetSummary($ieee);
        if (empty($ieee)) {
            $this->LogMessage('Keine IEEE-Adresse konfiguriert', KL_WARNING);
        } else {
            if ($this->ReadAttributeString('IEEE') != $ieee) {
                $this->WriteAttributeString('IEEE', $ieee);
            }
        }
    }

    /**
     * MessageSink
     *
     * @param  mixed $Time
     * @param  mixed $SenderID
     * @param  mixed $Message
     * @param  mixed $Data
     * @return void
     */
    public function MessageSink($Time, $SenderID, $Message, $Data)
    {
        parent::MessageSink($Time, $SenderID, $Message, $Data);
        if ($SenderID != $this->InstanceID) {
            return;
        }
        switch ($Message) {
            case IM_CHANGEATTRIBUTE:
                if ($Data[0] == 'IEEE') {
                    $this->UpdateDeviceInfo();
                }
                return;
        }
    }

    /**
     * GetConfigurationForm
     *
     * @return string
     */
    public function GetConfigurationForm()
    {
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $Form['elements'][0]['items'][1]['image'] = $this->ReadAttributeString('Icon');
        return json_encode($Form);
    }

    /**
     * RequestAction
     *
     * @param  string $ident
     * @param  mixed $value
     * @return void
     */
    public function RequestAction($ident, $value)
    {
        if ($ident == 'ShowIeeeEditWarning') {
            $this->UpdateFormField('IeeeWarning', 'visible', true);
            return;
        }
        if ($ident == 'EnableIeeeEdit') {
            $this->UpdateFormField('IEEE', 'enabled', true);
            return;
        }
        parent::RequestAction($ident, $value);
    }

    /**
     * UpdateDeviceInfo
     *
     * Exposes von der Erweiterung in Z2M anfordern und verarbeiten.
     *
     * @return bool
     */
    protected function UpdateDeviceInfo(): bool
    {
        // Aufruf der Methode aus der ModulBase-Klasse
        $Result = $this->LoadDeviceInfo();
        if (!$Result) {
            return false;
        }
        if (!count($Result)) {
            trigger_error($this->Translate('Device not found. Check topic.'), E_USER_NOTICE);
            return false;

        }
        if (!isset($Result['ieeeAddr'])) {
            $this->LogMessage($this->Translate('IEEE-Address missing.'), KL_WARNING);
            $Result['ieeeAddr'] = '';
        }

        // IEEE-Adresse bei Modulupdate von 4.x auf 5.x in der Instanz-Konfig ergänzen.
        $currentIEEE = $this->ReadPropertyString('IEEE');
        if (empty($currentIEEE) && ($currentIEEE !== $Result['ieeeAddr'])) {
            IPS_SetProperty($this->InstanceID, 'IEEE', $Result['ieeeAddr']);
            IPS_ApplyChanges($this->InstanceID);
        }

        // Model und Icon verarbeiten
        if (isset($Result['model']) && $Result['model'] !== 'Unknown Model') {
            $Model = $Result['model'];
            if ($this->ReadAttributeString('Model') !== $Model) {
                $this->UpdateDeviceIcon($Model);
            }
        }

        // Exposes enthalten?
        if (!isset($Result['exposes'])) {
            return false;
        }

        // JSON-Datei speichern
        $SaveResult = $this->SaveExposesToJson([
            'symconId'  => $this->InstanceID,
            'ieeeAddr'  => $Result['ieeeAddr'],
            'model'     => $Result['model'],
            'exposes'   => $Result['exposes']
        ]);

        $this->mapExposesToVariables($Result['exposes']);
        return $SaveResult;

    }

    private function UpdateDeviceIcon(string $Model): void
    {
        $Url = 'https://raw.githubusercontent.com/Koenkk/zigbee2mqtt.io/master/public/images/devices/' . $Model . '.png';
        $this->SendDebug('loadImage', $Url, 0);
        $ImageRaw = @file_get_contents($Url);
        if ($ImageRaw !== false) {
            $Icon = 'data:image/png;base64,' . base64_encode($ImageRaw);
            $this->WriteAttributeString('Icon', $Icon);
            $this->WriteAttributeString('Model', $Model);
        } else {
            $this->LogMessage('Fehler beim Herunterladen des Icons von URL: ' . $Url, KL_WARNING);
        }
    }
}
