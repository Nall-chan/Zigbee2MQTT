<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/ModulBase.php';

class Zigbee2MQTTGroup extends \Zigbee2MQTT\ModulBase
{
    /** @var mixed $ExtensionTopic Topic für den ReceiveFilter */
    protected static $ExtensionTopic = 'getGroupInfo/';

    /**
     * Create
     *
     * @return void
     */
    public function Create()
    {
        // Never delete this line!
        parent::Create();
        $this->RegisterPropertyInteger('GroupId', 0);
    }

    /**
     * ApplyChanges
     *
     * @return void
     */
    public function ApplyChanges()
    {
        $GroupId = $this->ReadPropertyInteger('GroupId');
        $GroupId = $GroupId ? 'Group Id: ' . $GroupId : '';
        $this->SetSummary($GroupId);
        //Never delete this line!
        parent::ApplyChanges();
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

        // Gruppenspezifische Informationen
        $dateiNamePattern = $instanceID . '.json';

        // Vollständiger Pfad mit Muster
        $dateiPfad = $vollerPfad . $dateiNamePattern;

        // Suche nach Dateien, die dem Muster entsprechen
        $files = glob($dateiPfad);

        // Überprüfung und Löschung der gefundenen Dateien
        foreach ($files as $file) {
            if (is_file($file)) {
                if (unlink($file)) {
                    $this->LogMessage('Datei erfolgreich gelöscht: ' . $file, KL_SUCCESS);
                } else {
                    $this->LogMessage('Fehler beim Löschen der Datei: ' . $file, KL_ERROR);
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

        $Result = $this->SendData('/SymconExtension/request/getGroupInfo/' . $mqttTopic);
        if (!$Result) {
            return false;
        }
        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' result', json_encode($Result), 0);

        if (!array_key_exists('foundGroup', $Result)) {
            trigger_error($this->Translate('Group not found. Check topic'), E_USER_NOTICE);
            return false;

        }
        unset($Result['foundGroup']);
        // Aufruf der Methode aus der ModulBase-Klasse
        $this->mapExposesToVariables($Result);
        $this->SaveExposesToJson($Result);
        return true;
    }

    /**
     * SaveExposesToJson
     *
     * Speichert die Exposes in einer JSON-Datei.
     *
     * @param array $Result Die Exposes-Daten.
     *
     * @return void
     */
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
}
