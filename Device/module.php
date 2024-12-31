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
     * Destroy
     *
     * Diese Methode wird aufgerufen, wenn die Instanz gelöscht wird.
     * Sie sorgt dafür, dass die zugehörige .json-Datei entfernt wird.
     *
     * @return void
     */
    public function Destroy()
    {
        // Wichtig: Zuerst die Parent Destroy Methode aufrufen
        parent::Destroy();

        // Holen der InstanceID
        $instanceID = $this->InstanceID;

        // Holen des Kernel-Verzeichnisses
        $kernelDir = IPS_GetKernelDir();

        // Definieren des Verzeichnisnamens
        $verzeichnisName = 'Zigbee2MQTTExposes';

        // Konstruktion des vollständigen Pfads zum Verzeichnis
        $vollerPfad = $kernelDir . $verzeichnisName . DIRECTORY_SEPARATOR;

        // Konstruktion des erwarteten Dateinamens mit InstanceID und Wildcard für ieeeAddr
        $dateiNamePattern = $instanceID . '.json';

        // Vollständiger Pfad mit Muster
        $dateiPfad = $vollerPfad . $dateiNamePattern;

        // Suche nach Dateien, die dem Muster entsprechen
        $files = glob($dateiPfad);

        // Überprüfung und Löschung der gefundenen Dateien
        foreach ($files as $file) {
            if (is_file($file)) {
                if (unlink($file)) {
                    $this->LogMessage("Datei erfolgreich gelöscht: $file", KL_SUCCESS);
                } else {
                    $this->LogMessage("Fehler beim Löschen der Datei: $file", KL_ERROR);
                }
            }
        }
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

        $Result = $this->SendData('/SymconExtension/request/getDeviceInfo/' . $mqttTopic);

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
        $kernelDir = rtrim(IPS_GetKernelDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $verzeichnisName = 'Zigbee2MQTTExposes';
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