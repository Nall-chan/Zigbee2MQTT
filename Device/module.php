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
        $this->RegisterAttributeString('Model', '');
        $this->RegisterAttributeString('Icon', '');

        // Setze Standardstatus
        $this->SetStatus(201); // Inaktiv bis IEEE konfiguriert
    }

    /**
     * ApplyChanges
     *
     * @return void
     */
    public function ApplyChanges()
    {
        // Prüfe zuerst ob IEEE-Adresse vorhanden
        $ieee = $this->ReadPropertyString('IEEE');
        if (empty($ieee)) {
            $this->LogMessage('Keine IEEE-Adresse konfiguriert', KL_WARNING);
            $this->SetStatus(201); // Instanz inaktiv
            return;
        }

        // Führe parent::ApplyChanges zuerst aus
        parent::ApplyChanges();

        // Setze Zusammenfassung nur wenn Instanz erfolgreich initialisiert
        if ($this->GetStatus() == 102) { // 102 = aktiv
            $this->SetSummary($ieee);
        }
    }

    /**
     * GetConfigurationForm
     *
     * @todo Expertenbutton um Schreibschutz vom Feld ieeeAddr aufzuheben.
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
     * UpdateDeviceInfo
     *
     * Exposes von der Erweiterung in Z2M anfordern und verarbeiten.
     *
     * @return bool
     */
    protected function UpdateDeviceInfo(): bool
    {

        $mqttTopic = $this->ReadPropertyString(self::MQTT_TOPIC);
        if (empty($mqttTopic)) {
            $this->LogMessage('MQTTTopic ist nicht gesetzt.', KL_WARNING);
            return false;
        }

        $Result = $this->SendData(self::SYMCON_DEVICE_INFO_REQUEST . $mqttTopic);

        if (!$Result) {
            return false;
        }

        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' result', json_encode($Result), 0);

        if (!array_key_exists('ieeeAddr', $Result)) {
            $this->LogMessage('Keine IEEE-Adresse in der Antwort gefunden.', KL_WARNING);
            return false;
        }

        // IEEE-Adresse verarbeiten
        $currentIEEE = $this->ReadPropertyString('IEEE');
        if (empty($currentIEEE) && ($currentIEEE !== $Result['ieeeAddr'])) {
            IPS_SetProperty($this->InstanceID, 'IEEE', $Result['ieeeAddr']);
            IPS_ApplyChanges($this->InstanceID);
        }

        // Model und Icon verarbeiten
        if (array_key_exists('model', $Result) && $Result['model'] !== 'Unknown Model') {
            $Model = $Result['model'];
            if ($this->ReadAttributeString('Model') !== $Model) {
                $this->UpdateDeviceIcon($Model);
            }
        }

        // JSON-Datei speichern
        if (isset($Result['ieeeAddr']) && isset($Result['exposes'])) {
            $this->SaveExposesToJson([
                'symconId'  => $this->InstanceID,
                'ieeeAddr'  => $Result['ieeeAddr'],
                'model'     => $Result['model'],
                'exposes'   => $Result['exposes']
            ]);
        }

        // Exposes verarbeiten
        if (!isset($Result['exposes'])) {
            return false;
        }
        $this->mapExposesToVariables($Result['exposes']);
        return true;

    }

    protected function SaveExposesToJson(array $Result): void
    {
        // JSON-Daten mit Pretty-Print erstellen
        $jsonData = json_encode($Result, JSON_PRETTY_PRINT);
        if ($jsonData === false) {
            $this->LogMessage('Fehler beim JSON-Encoding: ' . json_last_error_msg(), KL_ERROR);
            return;
        }

        // Definieren des Verzeichnisnamens
        $kernelDir = IPS_GetKernelDir();
        $verzeichnisName = self::EXPOSES_DIRECTORY;
        $vollerPfad = $kernelDir . $verzeichnisName . DIRECTORY_SEPARATOR;

        if (!file_exists($vollerPfad) && !mkdir($vollerPfad, 0755, true)) {
            $this->LogMessage('Fehler beim Erstellen des Verzeichnisses "' . $verzeichnisName . '"', KL_ERROR);
            return;
        }

        // Dateipfad für die JSON-Datei basierend auf InstanceID und groupID
        $dateiPfad = $vollerPfad . $this->InstanceID . '.json';

        // Schreiben der JSON-Daten in die Datei
        if (file_put_contents($dateiPfad, $jsonData) === false) {
            $this->LogMessage('Fehler beim Schreiben der JSON-Datei.', KL_ERROR);
        }
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