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
        try {
            $mqttTopic = $this->ReadPropertyString('MQTTTopic');
            if (empty($mqttTopic)) {
                IPS_LogMessage(__CLASS__, "MQTTTopic ist nicht gesetzt.");
                return false;
            }

            $Result = $this->SendData('/SymconExtension/request/getDeviceInfo/' . $mqttTopic);
            $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' result', json_encode($Result), 0);

            if (empty($Result) || !is_array($Result)) {
                IPS_LogMessage(__CLASS__, "Keine Daten empfangen für MQTTTopic '$mqttTopic'.");
                return false;
            }

            if (!array_key_exists('ieeeAddr', $Result)) {
                IPS_LogMessage(__CLASS__, "Keine IEEE-Adresse in der Antwort gefunden.");
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
                $this->SaveDeviceJSON($Result);
            }

            // Exposes verarbeiten
            if (isset($Result['exposes'])) {
                $this->mapExposesToVariables($Result['exposes']);
                return true;
            }

            return false;

        } catch (Exception $e) {
            IPS_LogMessage(__CLASS__, "Fehler in UpdateDeviceInfo: " . $e->getMessage());
            return false;
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
            IPS_LogMessage(__CLASS__, "Fehler beim Herunterladen des Icons von URL: $Url");
        }
    }

    private function SaveDeviceJSON(array $Result): void
    {
        $dataToSave = [
            'symconId'  => $this->InstanceID,
            'ieeeAddr'  => $Result['ieeeAddr'],
            'model'     => $Result['model'],
            'exposes'   => $Result['exposes']
        ];

        $jsonData = json_encode($dataToSave, JSON_PRETTY_PRINT);
        if ($jsonData === false) {
            IPS_LogMessage(__CLASS__, "Fehler beim JSON-Encoding: " . json_last_error_msg());
            return;
        }

        $kernelDir = rtrim(IPS_GetKernelDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $verzeichnisName = 'Zigbee2MQTTExposes';
        $vollerPfad = $kernelDir . $verzeichnisName . DIRECTORY_SEPARATOR;

        if (!file_exists($vollerPfad) && !mkdir($vollerPfad, 0755, true)) {
            IPS_LogMessage(__CLASS__, "Fehler beim Erstellen des Verzeichnisses '$verzeichnisName'.");
            return;
        }

        $dateiPfad = $vollerPfad . $this->InstanceID . '.json';
        if (file_put_contents($dateiPfad, $jsonData) === false) {
            IPS_LogMessage(__CLASS__, "Fehler beim Schreiben der JSON-Datei.");
        }
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
                    IPS_LogMessage(__CLASS__, "Datei erfolgreich gelöscht: $file");
                } else {
                    IPS_LogMessage(__CLASS__, "Fehler beim Löschen der Datei: $file");
                }
            }
        }
    }
}
