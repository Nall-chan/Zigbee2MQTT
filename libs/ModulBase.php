<?php

declare(strict_types=1);

namespace Zigbee2MQTT;

require_once __DIR__ . '/BufferHelper.php';
require_once __DIR__ . '/SemaphoreHelper.php';
require_once __DIR__ . '/VariableProfileHelper.php';
require_once __DIR__ . '/MQTTHelper.php';
require_once __DIR__ . '/ColorHelper.php';

/**
 * ModulBase
 *
 * Basisklasse für Geräte (Devices module.php) und Gruppen (Groups module.php)
 */
abstract class ModulBase extends \IPSModule
{
    use BufferHelper;
    use Semaphore;
    use ColorHelper;
    use VariableProfileHelper;
    use SendData;

    /**
     * @var array STATE_PATTERN
     * Definiert Nomenklatur für State-Variablen
     *      KEY:
     *      - BASE     'state' (Basisbezeichner)
     *      - SUFFIX:   Zusatzbezeichner
     *          - NUMERIC:   _1, _2, etc.
     *          - DIRECTION: _left, _right
     *          - COMBINED:  _left_1, _right_2
     *      - MQTT:    Validiert MQTT-Payload (state, state_l1)
     *      - SYMCON:  Validiert Symcon-Variablen (state, stateL1)
     */
    private const STATE_PATTERN = [
        'PREFIX' => '',
        'BASE'   => 'state',
        'SUFFIX' => [
            'NUMERIC'   => '_[0-9]+',
            'DIRECTION' => '_(?:left|right)',
            'COMBINED'  => '_(?:left|right)_[0-9]+'
        ],
        'MQTT' => '/^state(?:_[a-z0-9]+)?$/i',  // Für MQTT-Payload
        'SYMCON' => '/^[Ss]tate(?:(?:[Ll][0-9]+)|(?:[Ll]eft|[Rr]ight)(?:[Ll][0-9]+)?)?$/'
    ];

    /**
     * @var array BUFFER_KEYS
     * Definiert die benutzen Namen für die Instanzbuffer
     * - PROCESSING_MIGRATION true bei laufender Migration
     * - MQTT_SUSPENDED MQTT true bei Nachrichten nicht verarbeiten
     */
    private const BUFFER_KEYS = [
        'PROCESSING_MIGRATION' => 'processingMigration',
        'MQTT_SUSPENDED' => 'mqttSuspended'
    ];

    /**
     * @var array FLOAT_UNITS
     * Entscheidet über Float oder Integer profile
     */
    private const FLOAT_UNITS = [
        '°C', '°F', 'K', 'mg/L', 'µg/m³', 'g/m³', 'mV', 'V', 'kV', 'µV', 'A', 'mA', 'µA', 'W', 'kW', 'MW', 'GW',
        'Wh', 'kWh', 'MWh', 'GWh', 'Hz', 'kHz', 'MHz', 'GHz', 'cd', 'ppm', 'ppb', 'ppt', 'pH', 'm', 'cm',
        'mm', 'µm', 'nm', 'l', 'ml', 'dl', 'm³', 'cm³', 'mm³', 'g', 'kg', 'mg', 'µg', 'ton', 'lb', 's', 'ms', 'µs',
        'ns', 'min', 'h', 'd', 'rad', 'sr', 'Bq', 'Gy', 'Sv', 'kat', 'mol', 'mol/l', 'N', 'Pa', 'kPa', 'MPa', 'GPa',
        'bar', 'mbar', 'atm', 'torr', 'psi', 'ohm', 'kohm', 'mohm', 'S', 'mS', 'µS', 'F', 'mF', 'µF', 'nF', 'pF', 'H',
        'mH', 'µH', '%', 'dB', 'dBA', 'dBC'
    ];

    /** @var string $ExtensionTopic Muss überschrieben werden für den ReceiveFilter */
    protected static $ExtensionTopic = '';

    /**
     * @var array<array{type: string, feature: string, profile: string, variableType: string}
     * Ein Array, das Standardprofile für bestimmte Gerätetypen und Eigenschaften definiert.
     *
     * Jedes Element des Arrays enthält folgende Schlüssel:
     *
     * - 'group_type' (string): Der Gerätetyp, z. B. 'cover' oder 'light'. Ein leerer Wert ('') bedeutet, dass der Typ nicht relevant ist.
     * - 'feature' (string): Die spezifische Eigenschaft oder das Feature des Geräts, z. B. 'position', 'temperature'.
     * - 'profile' (string): Das Symcon-Profil, das für dieses Feature verwendet wird, z. B. '~Shutter.Reversed' oder '~Battery.100'.
     * - 'variableType' (string): Der Variablentyp, der für dieses Profil verwendet wird, z. B. VARIABLETYPE_INTEGER für Integer oder VARIABLETYPE_FLOAT für Gleitkommazahlen.
     *
     * Beispieleintrag:
     * @var array<string,array{
     *   'group_type' => 'cover',
     *   'feature' => 'position',
     *   'profile' => '~Shutter.Reversed',
     *   'variableType' => VARIABLETYPE_INTEGER
     * }>
     */
    protected static $VariableUseStandardProfile = [
        ['group_type' => 'cover', 'feature' => 'position', 'profile' => '~Shutter.Reversed', 'variableType' => VARIABLETYPE_INTEGER],
        ['group_type' => '', 'feature' => 'temperature', 'profile' => '~Temperature', 'variableType' => VARIABLETYPE_FLOAT],
        ['group_type' => '', 'feature' => 'humidity', 'profile' => '~Humidity.F', 'variableType' => VARIABLETYPE_FLOAT],
        ['group_type' => '', 'feature' => 'local_temperature', 'profile' => '~Temperature', 'variableType' => VARIABLETYPE_FLOAT],
        ['group_type' => '', 'feature' => 'battery', 'profile' => '~Battery.100', 'variableType' => VARIABLETYPE_INTEGER],
        ['group_type' => '', 'feature' => 'current', 'profile' => '~Ampere', 'variableType' => VARIABLETYPE_FLOAT],
        ['group_type' => '', 'feature' => 'energy', 'profile' => '~Electricity', 'variableType' => VARIABLETYPE_FLOAT],
        ['group_type' => '', 'feature' => 'power', 'profile' => '~Watt', 'variableType' => VARIABLETYPE_FLOAT],
        ['group_type' => '', 'feature' => 'battery', 'profile' => '~Battery.100', 'variableType' => VARIABLETYPE_INTEGER],
        ['group_type' => '', 'feature' => 'occupancy', 'profile' => '~Presence', 'variableType' => VARIABLETYPE_BOOLEAN],
        ['group_type' => '', 'feature' => 'pi_heating_demand', 'profile' => '~Valve', 'variableType' => VARIABLETYPE_INTEGER],
        ['group_type' => '', 'feature' => 'presence', 'profile' => '~Presence', 'variableType' => VARIABLETYPE_BOOLEAN],
        ['group_type' => '', 'feature' => 'illuminance_lux', 'profile' => '~Illumination', 'variableType' => VARIABLETYPE_INTEGER],
        ['group_type' => '', 'feature' => 'child_lock', 'profile' =>'~Lock', 'variableType' => VARIABLETYPE_BOOLEAN],
        ['group_type' => '', 'feature' => 'window_open', 'profile' => '~Window', 'variableType' => VARIABLETYPE_BOOLEAN],
        ['group_type' => '', 'feature' => 'valve', 'profile' => '~Valve', 'variableType' => VARIABLETYPE_INTEGER],
        ['group_type' => '', 'feature' => 'window_detection', 'profile' =>'~Window', 'variableType' => VARIABLETYPE_BOOLEAN],
    ];

    /**
     * @var array<string,array{
     *   type: int,
     *   name: string,
     *   profile: string,
     *   scale?: float,
     *   ident?: string,
     *   enableAction: bool
     *
     * Definiert spezielle Variablen mit vordefinierten Eigenschaften
     *
     * Schlüssel:
     *   - type: int Variablentyp
     *   - name: string Anzeigename der Variable
     *   - profile: string Profilname oder leer
     *   - scale?: float Optional: Skalierungsfaktor
     *   - ident?: string Optional: Benutzerdefinierter Identifier
     *   - enableAction: bool Aktionen erlaubt (true/false)
     */
    protected static $specialVariables = [
        'last_seen' => ['type' => VARIABLETYPE_INTEGER, 'name' => 'Last Seen', 'profile' => '~UnixTimestamp', 'scale' => 0.001, 'enableAction' => false],
        'color_mode' => ['type' => VARIABLETYPE_STRING, 'name' => 'Color Mode', 'profile' => '', 'enableAction' => false],
        'update' => ['type' => VARIABLETYPE_BOOLEAN, 'name' => 'Update Available', 'profile' => '~Alert', 'enableAction' => false],
        'device_temperature' => ['type' => VARIABLETYPE_FLOAT, 'name' => 'Device Temperature', 'profile' => '~Temperature', 'enableAction' => false],
        'brightness' => ['type' => VARIABLETYPE_INTEGER, 'ident' => 'brightness', 'profile' => '~Intensity.100', 'scale' => 1, 'enableAction' => true],
        'voltage' => ['type' => VARIABLETYPE_FLOAT, 'ident' => 'voltage', 'profile' => '~Volt', 'enableAction' => false],
    ];

    /**
     * Definiert Status-Variablen mit festgelegten Wertebereichen
     *
     * Struktur:
     * [
     *   'VariablenName' => [
     *      'type'     => string,   // Typ der Variable (z.B. 'automode', 'valve')
     *      'dataType' => integer,  // IPS Variablentyp (VARIABLETYPE_*)
     *      'values'   => array     // Erlaubte Werte für die Variable
     *   ]
     * ]
     *
     * @var array $stateDefinitions Array mit Status-Definitionen
     */
    protected static $stateDefinitions = [
        'auto_lock' => ['type' => 'automode', 'dataType' => VARIABLETYPE_STRING, 'values' => ['AUTO', 'MANUAL']],
        'valve_state' => ['type' => 'valve', 'dataType' => VARIABLETYPE_STRING, 'values' => ['OPEN', 'CLOSED']],
    ];

    /** @var array $stringVariablesNoResponse
     *
     * Erkennt String-Variablen ohne Rückmeldung seitens Z2M
     * Aktualisiert die in Symcon angelegte Variable direkt nach dem Senden des Set-Befehls
     * Zur einfacheren Wartung als table angelegt. Somit muss der Code bei späteren Ergänzungen nicht angepasst werden.
     *
     * Typische Anwendungsfälle:
     * - Effekt-Modi bei Leuchtmitteln (z.B. "EFFECT"), bei denen der zuletzt verwendete Effekt
     *   angezeigt werden soll.
     *
     * Beispiel:
     * - 'effect': Aktualisiert den zuletzt gesetzten Effekt.
     */
    protected static $stringVariablesNoResponse = [
        'effect',
    ];

// Kernfunktionen

    /**
     * Wird einmalig beim Erstellen einer Instanz aufgerufen
     *
     * Führt folgende Aktionen aus:
     * - Verbindet mit dem MQTT-Parent ({C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850})
     * - Registriert Properties für MQTT-Basis-Topic und MQTT-Topic
     * - Initialisiert TransactionData Array
     * - Erstellt Zigbee2MQTTExposes Verzeichnis wenn nicht vorhanden
     * - Prüft und erstellt JSON-Datei für Geräteinfos
     *
     * @return void
     *
     * @throws Exception Wenn das Zigbee2MQTTExposes Verzeichnis nicht erstellt werden kann
     *
     * @see checkAndCreateJsonFile()
     * @see ConnectParent()
     * @see RegisterPropertyString()
     */
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
        $this->RegisterPropertyString(self::MQTT_BASE_TOPIC, '');
        $this->RegisterPropertyString(self::MQTT_TOPIC, '');
        $this->TransactionData = [];

        // Vollständigen Pfad zum Verzeichnis erstellen
        $neuesVerzeichnis = rtrim(IPS_GetKernelDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Zigbee2MQTTExposes';

        // Verzeichnis erstellen wenn nicht vorhanden
        if (!is_dir($neuesVerzeichnis)) {
            if (!mkdir($neuesVerzeichnis)) {
                $this->SendDebug(__FUNCTION__, 'Fehler beim Erstellen des Verzeichnisses: ' . $neuesVerzeichnis, 0);
            }
        }

        // JSON-Prüfung nur wenn MQTTTopic gesetzt
        if (!empty($this->ReadPropertyString(self::MQTT_TOPIC))) {
            $this->checkAndCreateJsonFile();
        }
    }

    /**
     * Wird aufgerufen bei Änderungen in der Modulkonfiguration
     *
     * Führt folgende Aktionen aus:
     * - Verbindet mit MQTT-Parent
     * - Liest MQTT Basis- und Geräte-Topic
     * - Setzt Filter für eingehende MQTT-Nachrichten
     * - Aktualisiert Instanz-Status (aktiv/inaktiv)
     * - Prüft und aktualisiert Geräteinformationen (deviceID.json)
     *
     * Bedingungen für Aktivierung:
     * - Basis-Topic und MQTT-Topic müssen gesetzt sein
     * - Parent muss aktiv sein
     * - System muss bereit sein (KR_READY)
     *
     * @return void
     *
     * @throws Exception Bei fehlgeschlagener Filter-Konfiguration
     *
     * @see SetReceiveDataFilter()
     * @see checkAndCreateJsonFile()
     * @see SetStatus()
     */
    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
        $BaseTopic = $this->ReadPropertyString(self::MQTT_BASE_TOPIC);
        $MQTTTopic = $this->ReadPropertyString(self::MQTT_TOPIC);
        $this->TransactionData = [];
        if (empty($BaseTopic) || empty($MQTTTopic)) {
            $this->SetStatus(IS_INACTIVE);
            $this->SetReceiveDataFilter('NOTHING_TO_RECEIVE'); //block all
            return;
        }

        //Setze Filter für ReceiveData
        $Filter1 = preg_quote('"Topic":"' . $BaseTopic . '/' . $MQTTTopic);
        $Filter2 = preg_quote('"Topic":"' . $BaseTopic . '/SymconExtension/response/' . static::$ExtensionTopic . $MQTTTopic);
        $this->SendDebug('Filter', '.*(' . $Filter1 . '|' . $Filter2 . ').*', 0);
        $this->SetReceiveDataFilter('.*(' . $Filter1 . '|' . $Filter2 . ').*');

        $this->SetStatus(IS_ACTIVE);

        // Nur ein UpdateDeviceInfo wenn Parent aktiv und System bereit
        if (($this->HasActiveParent()) && (IPS_GetKernelRunlevel() == KR_READY) && ($this->GetStatus() != IS_CREATING)) {
            $this->checkAndCreateJsonFile();
        }
    }

    /**
     * Verarbeitet Aktionsanforderungen für Variablen
     *
     * Diese Methode wird automatisch aufgerufen, wenn eine Variable über das Webfront
     * oder ein Script geändert wird. Sie verarbeitet verschiedene Arten von Aktionen:
     *
     * Aktionstypen:
     * - UpdateInfo: Aktualisiert Geräteinformationen
     * - presets: Verarbeitet vordefinierte Einstellungen
     * - String-Variablen ohne Rückmeldung: Direkte Aktualisierung
     * - Farbvariablen: Spezielle Behandlung von RGB/HSV/etc.
     * - Status-Variablen: ON/OFF und andere Zustände
     * - Standard-Variablen: Allgemeine Werteänderungen
     *
     * @param string $ident Identifikator der Variable (z.B. 'state', 'UpdateInfo')
     * @param mixed $value Neuer Wert für die Variable
     *
     * @return void
     *
     * @see handlePresetVariable()
     * @see handleStringVariableNoResponse()
     * @see handleColorVariable()
     * @see handleStateVariable()
     * @see handleStandardVariable()
     */
    public function RequestAction($ident, $value): void
    {
        $this->SendDebug(__FUNCTION__, 'Aufgerufen für Ident: ' . $ident . ' mit Wert: ' . json_encode($value), 0);

        $handled = match (true) {
            //Behandelt UpdateInfo
            $ident == 'UpdateInfo' => $this->UpdateDeviceInfo(),
            // Behandelt Presets
            strpos($ident, 'presets') !== false => $this->handlePresetVariable($ident, $value),
            // Behandelt String-Variablen ohne Rückmeldung
            in_array($ident, self::$stringVariablesNoResponse) => $this->handleStringVariableNoResponse($ident, $value),
            // Behandelt Farbvariablen
            strpos($ident, 'color') === 0 => $this->handleColorVariable($ident, $value),
            // Behandelt Status-Variablen
            preg_match(self::STATE_PATTERN['SYMCON'], $ident) => $this->handleStateVariable($ident, $value),
            // Behandelt Standard-Variablen
            default => $this->handleStandardVariable($ident, $value),
        };
        // Debug-Ausgabe bei nicht behandelten Ident
        if ($handled === false) {
            $this->SendDebug(__FUNCTION__, 'Keine passende Aktion für Ident: ' . $ident . ' gefunden', 0);
        }
    }

    /**
     * Verarbeitet eingehende MQTT-Nachrichten
     *
     * Diese Methode wird automatisch aufgerufen, wenn eine MQTT-Nachricht empfangen wird.
     * Der Verarbeitungsablauf ist wie folgt:
     * 1. Prüft ob Instanz im CREATE-Status ist
     * 2. Validiert Basis-Anforderungen (MQTT Topics)
     * 3. Dekodiert die JSON-Nachricht
     * 4. Extrahiert das MQTT-Topic
     * 5. Verarbeitet spezielle Nachrichtentypen:
     *    - Verfügbarkeitsstatus (availability)
     *    - Symcon Extension Antworten
     *    - Expose-Informationen
     *
     * @param string $JSONString Die empfangene MQTT-Nachricht im JSON-Format
     *
     * @return string Leerer String als Rückgabewert
     *
     * @throws Exception Bei JSON-Dekodierungsfehlern
     *
     * @see validateBasicRequirements()
     * @see parseMessage()
     * @see extractTopic()
     * @see handleAvailability()
     * @see handleSymconResponses()
     * @see processPayload()
     */
    public function ReceiveData($JSONString)
    {
        // Während Migration keine MQTT Nachrichten verarbeiten
        if($this->GetBuffer(self::BUFFER_KEYS['MQTT_SUSPENDED']) === 'true') {
            return '';
        }
        // Instanz im CREATE-Status überspringen
        if ($this->GetStatus() == IS_CREATING) {
            return '';
        }
        // Basis-Anforderungen validieren
        if (!$this->validateBasicRequirements($JSONString)) {
            return '';
        }
        // JSON-Nachricht dekodieren
        $messageData = $this->parseMessage($JSONString);
        if (!$messageData) {
            return '';
        }
        // Topic extrahieren
        $topic = $this->extractTopic($messageData);
        if (!$topic) {
            return '';
        }
        // Behandelt Verfügbarkeitsstatus
        if ($this->handleAvailability($topic, $messageData)) {
            return '';
        }
        // Behandelt Symcon Extension Antworten
        if ($this->handleSymconResponses($topic, $messageData)) {
            return '';
        }
        // Verarbeitet Payload
        return $this->processPayload($messageData);
    }

    /**
     * Führt eine Migration von Objekt-Idents durch, indem es Kinder-Objekte dieser Instanz durchsucht,
     * auf definierte Kriterien überprüft und bei Bedarf umbenennt.
     *
     * Ruft zuerst die Elternklasse-Methode auf und bearbeitet anschließend die Idents:
     * - Überprüfung, ob der Ident mit "Z2M_" beginnt
     * - Konvertierung des Ident ins snake_case
     * - Loggt sowohl Fehler als auch erfolgreiche Änderungen
     *
     * @param string $JSONData JSON-Daten zur Steuerung der Migration (derzeit nicht verwendet)
     * @return void Gibt keinen Wert zurück
     */
    public function Migrate($JSONData)
    {
        // Flag für laufende Migration setzen
        $this->SetBuffer(self::BUFFER_KEYS['MQTT_SUSPENDED'], 'true');
        $this->SetBuffer(self::BUFFER_KEYS['PROCESSING_MIGRATION'], 'true');

        // 1) Suche alle Kinder-Objekte dieser Instanz
        // 2) Prüfe, ob ihr Ident z. B. mit "Z2M_" beginnt
        // 3) Bilde den neuen Ident (snake_case) und setze ihn

        $childrenIDs = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($childrenIDs as $childID) {
            // Nur weitermachen, wenn es sich um eine Variable handelt
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] !== OBJECTTYPE_VARIABLE) {
                continue;
            }

            $oldIdent = $obj['ObjectIdent'];
            if ($oldIdent == '') {
                // Hat keinen Ident, also ignorieren
                continue;
            }

            // Nur solche Idents, die mit 'Z2M_' beginnen:
            if (substr($oldIdent, 0, 4) !== 'Z2M_') {
                // Überspringen
                continue;
            }

            // Neuen Ident bilden
            $newIdent = self::convertToSnakeCase($oldIdent);

            // Versuchen zu setzen
            $result = @IPS_SetIdent($childID, $newIdent);
            if ($result === false) {
                $this->LogMessage(__FUNCTION__ . ' : Fehler: Ident "' . $newIdent . '" konnte nicht für Variable #{$childID} gesetzt werden!', KL_ERROR);
            } else {
                $this->LogMessage(__FUNCTION__ . ' : Variable #' . $childID . ': "' . $oldIdent . '" wurde geändert zu "' . $newIdent . '"', KL_NOTIFY);
            }
        }

        // Flag für beendete Migration wieder setzen
        $this->SetBuffer(self::BUFFER_KEYS['MQTT_SUSPENDED'], 'false');
        $this->SetBuffer(self::BUFFER_KEYS['PROCESSING_MIGRATION'], 'false');
    }

    /**
     * Diese Hilfsfunktion entfernt das Prefix "Z2M_" und
     * wandelt CamelCase in lower_snake_case um.
     *
     * Beispiele:
     * - "color_temp" -> "color_temp"
     * - "brightnessABC" -> "brightness_a_b_c"
     */
    private static function convertToSnakeCase(string $oldIdent): string
    {
        // 1) Prefix "Z2M_" entfernen
        $withoutPrefix = preg_replace('/^Z2M_/', '', $oldIdent);

        // 2) Vor jedem Großbuchstaben einen Unterstrich einfügen
        //    Bsp: "ColorTemp" -> "_Color_Temp"
        //    Bsp: "BrightnessABC" -> "_Brightness_A_B_C"
        $withUnderscore = preg_replace('/([A-Z])/', '_$1', $withoutPrefix);

        // 3) Falls jetzt am Anfang ein "_" ist, entfernen
        $withUnderscore = ltrim($withUnderscore, '_');

        // 4) Mehrere aufeinanderfolgende Unterstriche auf einen reduzieren
        $withUnderscore = preg_replace('/_+/', '_', $withUnderscore);

        // 5) Jetzt alles in kleingeschrieben
        $snakeCase = strtolower($withUnderscore);

        return $snakeCase;
    }


// MQTT Kommunikation

    /**
     * Prüft die grundlegenden Voraussetzungen für die MQTT-Kommunikation
     *
     * Validiert:
     * - Existenz des MQTT Basis-Topics
     * - Existenz des MQTT Geräte-Topics
     *
     * @param string $JSONString Die zu validierende JSON-Nachricht
     *
     * @return bool True wenn alle Voraussetzungen erfüllt sind, sonst False
     *
     * @see ReadPropertyString()
     * @see SendDebug()
     */
    private function validateBasicRequirements($JSONString): bool
    {
        $baseTopic = $this->ReadPropertyString(self::MQTT_BASE_TOPIC);
        $mqttTopic = $this->ReadPropertyString(self::MQTT_TOPIC);

        if (empty($baseTopic) || empty($mqttTopic)) {
            $this->SendDebug(__FUNCTION__, 'BaseTopic oder MQTTTopic ist leer', 0);
            return false;
        }
        return true;
    }

    /**
     * Dekodiert und validiert eine MQTT-JSON-Nachricht
     *
     * Verarbeitung:
     * - Dekodiert JSON-String in Array
     * - Prüft auf JSON-Decodierung-Fehler
     * - Validiert Vorhandensein des Topic-Felds
     *
     * @param string $JSONString Die zu dekodierende MQTT-Nachricht
     *
     * @return array|null Decodiertes Nachrichten-Array oder null bei Fehlern
     *
     * @throws Exception Bei JSON-Dekodierungsfehlern
     *
     * @see json_decode()
     * @see SendDebug()
     */
    private function parseMessage($JSONString): ?array
    {
        $buffer = json_decode($JSONString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SendDebug(__FUNCTION__, 'JSON Decodierung fehlgeschlagen: ' . json_last_error_msg(), 0);
            return null;
        }

        if (!isset($buffer['Topic'])) {
            $this->SendDebug(__FUNCTION__, 'Topic nicht gefunden', 0);
            return null;
        }

        return $buffer;
    }

    /**
     * Extrahiert das MQTT-Topic aus den Nachrichtendaten
     *
     * Verarbeitung:
     * - Liest Basis-Topic aus den Eigenschaften
     * - Entfernt Basis-Topic vom empfangenen Topic
     * - Teilt das resultierende Topic in seine Bestandteile
     *
     * @param array $messageData Array mit den MQTT-Nachrichtendaten
     *
     * @return array|null Array mit Topic-Bestandteilen oder null bei Fehler
     *
     * @see ReadPropertyString()
     * @see substr()
     * @see explode()
     */
    private function extractTopic(array $messageData): ?array
    {
        $baseTopic = $this->ReadPropertyString(self::MQTT_BASE_TOPIC);
        $receiveTopic = $messageData['Topic'];
        $topic = substr($receiveTopic, strlen($baseTopic) + 1);
        return explode('/', $topic);
    }

    /**
     * Verarbeitet den Verfügbarkeitsstatus eines Zigbee-Geräts
     *
     * Funktionen:
     * - Prüft ob Topic ein Verfügbarkeits-Topic ist
     * - Erstellt/Aktualisiert Z2M.DeviceStatus Profil
     * - Registriert/Aktualisiert Verfügbarkeits-Variable
     *
     * @param array $topics Array mit Topic-Bestandteilen
     * @param array $messageData Array mit MQTT-Nachrichtendaten
     *
     * @return bool True wenn Verfügbarkeit verarbeitet wurde, sonst False
     *
     * @throws Exception Bei Fehlern während der Profil- oder Variablenerstellung
     *
     * @see RegisterProfileBoolean()
     * @see RegisterVariableBoolean()
     * @see SetValue()
     */
    private function handleAvailability(array $topics, array $messageData): bool
    {
        if (end($topics) !== self::AVAILABILITY_TOPIC) {
            return false;
        }

        if (!IPS_VariableProfileExists('Z2M.DeviceStatus')) {
            $this->RegisterProfileBoolean(
                'Z2M.DeviceStatus',
                'Network',
                '',
                '',
                [
                    [false, $this->Translate('Offline'),  '', 0xFF0000],
                    [true,  $this->Translate('Online'),   '', 0x00FF00]
                ]
            );
        }
        $this->RegisterVariableBoolean('device_status', $this->Translate('Availability'), 'Z2M.DeviceStatus');
        $this->SetValue('device_status', $messageData['Payload'] == '{"state":"online"}');
        return true;
    }

    /**
     * Verarbeitet Antworten von Symcon Extension Anfragen
     *
     * Funktionalität:
     * - Prüft ob Topic eine Symcon Extension Antwort ist
     * - Verarbeitet Device/Group Info Antworten
     * - Aktualisiert Transaktionsdaten wenn vorhanden
     *
     * Antwort-Typen:
     * - getDeviceInfo: Informationen über ein einzelnes Gerät
     * - getGroupInfo: Informationen über eine Gerätegruppe
     *
     * @param array $topics Array mit Topic-Bestandteilen
     * @param array $messageData Array mit MQTT-Nachrichtendaten
     *
     * @return bool True wenn eine Symcon-Antwort verarbeitet wurde, sonst False
     *
     * @see UpdateTransaction()
     * @see ReadPropertyString()
     */
    private function handleSymconResponses(array $topics, array $messageData): bool
    {
        $mqttTopic = $this->ReadPropertyString(self::MQTT_TOPIC);
        $fullTopic = implode('/', $topics);

        if ($fullTopic === self::SYMCON_DEVICE_INFO . $mqttTopic ||
            $fullTopic === self::SYMCON_GROUP_INFO . $mqttTopic) {
            $payload = json_decode(mb_convert_encoding($messageData['Payload'], 'UTF-8', 'ISO-8859-1'), true);
            if (isset($payload['transaction'])) {
                $this->UpdateTransaction($payload);
            }
            return true;
        }
        return false;
    }

    /**
     * Sendet einen Set-Befehl an das Gerät über MQTT
     *
     * Diese Methode generiert das MQTT-Topic für den Set-Befehl basierend auf der Konfiguration
     * und sendet das übergebene Payload an das Gerät.
     *
     * Format:
     * - Topic: /<MQTTTopic>/set
     * - Payload: Array mit Schlüssel-Wert-Paaren
     *
     * @param array $Payload Das Payload, das an das Gerät gesendet werden soll
     *
     * @return void
     *
     * @throws Exception Bei Fehlern während des Sendens
     *
     * @see SendData()
     * @see ReadPropertyString()
     * @see SendDebug()
     */
    public function SendSetCommand(array $Payload)
    {
        // MQTT-Topic für den Set-Befehl generieren
        $Topic = '/' . $this->ReadPropertyString('MQTTTopic') . '/set';

        // Debug-Ausgabe des zu sendenden Payloads
        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: zu sendendes Payload: ', json_encode($Payload), 0);

        // Sende die Daten an das Gerät
        try {
            $this->SendData($Topic, $Payload, 0);
        } catch (Exception $e) {
            $this->SendDebug(__FUNCTION__, 'Fehler beim Senden des Set-Befehls: ' . $e->getMessage(), 0);
        }
    }

    /**
     * Verarbeitet die empfangenen MQTT-Payload-Daten
     *
     * Verarbeitungsschritte:
     * 1. JSON-Dekodierung des Payloads mit UTF-8 Konvertierung
     * 2. Prüfung der JSON-Decodierung auf Fehler
     * 3. Debug-Ausgabe bei Fehlern
     *
     * @param array $messageData Array mit den MQTT-Nachrichtendaten
     *                          Erwartet wird ein Array mit einem 'Payload'-Schlüssel
     *                          Der Payload muss JSON-kodierte Daten enthalten
     *
     * @return string Leerer String bei Fehler, sonst verarbeitete Payload-Daten
     *
     * @throws Exception Bei Fehler in der JSON-Dekodierung (wird intern behandelt)
     *
     * @internal Diese Methode wird vom MQTT-Handler aufgerufen
     *
     * @example
     * // Verarbeitung einer MQTT-Nachricht
     * $messageData = ['Payload' => '{"temperature": 21.5}'];
     * $result = $this->processPayload($messageData);
     */
    private function processPayload(array $messageData): string
    {
        // Payload dekodieren
        $payload = json_decode(mb_convert_encoding($messageData['Payload'], 'UTF-8', 'ISO-8859-1'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SendDebug(__FUNCTION__, 'Payload Decodierung fehlgeschlagen', 0);
            return '';
        }

        // Exposes verarbeiten wenn vorhanden
        if (isset($payload['exposes'])) {
            $this->mapExposesToVariables($payload['exposes']);
        }

        // Variablentypen anhängen
        $payloadWithTypes = $this->AppendVariableTypes($payload);
        if (!is_array($payloadWithTypes)) {
            return '';
        }

        // Bekannte Variablen laden
        $knownVariables = $this->getKnownVariables();

        // Payload-Daten verarbeiten
        foreach ($payloadWithTypes as $key => $value) {
            // Typ-Informationen überspringen
            if (strpos($key, '_type') !== false) {
                continue;
            }

            $this->SendDebug(__FUNCTION__, sprintf('Verarbeite: Key=%s, Value=%s', $key, is_array($value) ? json_encode($value) : strval($value)), 0);

            // Sonderfälle prüfen und verarbeiten
            if ($this->processSpecialVariable($key, $value)) {
                continue;
            }

            // Allgemeine Variablen verarbeiten
            $this->processVariable($key, $value, $knownVariables);
        }
        return '';
    }

// Variablenmanagement

    /**
     * Setzt den Wert einer Variable unter Berücksichtigung verschiedener Typen und Formatierungen
     *
     * Verarbeitung:
     * 1. Prüft Existenz der Variable
     * 2. Konvertiert Wert entsprechend Variablentyp
     * 3. Wendet Profilzuordnungen an
     * 4. Behandelt Spezialfälle (z.B. ColorTemp)
     *
     * Unterstützte Variablentypen:
     * 1. State-Variablen:
     *    - state: ON/OFF -> true/false
     *    - stateL1: Nummerierte States
     *    - stateLeft: Richtungs-States
     *    - stateLeftL1: Kombinierte States
     *
     * 2. Spezielle Variablen:
     *    - color: RGB-Farbwerte
     *    - color_temp: Farbtemperatur mit Kelvin-Konvertierung
     *    - preset: Vordefinierte Werte
     *
     * 3. Standard-Variablen:
     *    - Boolean: Automatische ON/OFF Konvertierung
     *    - Integer/Float: Typkonvertierung mit Einheitenbehandlung
     *    - String: Direkte Wertzuweisung
     *
     * @param string $ident Identifier der Variable (z.B. "state", "color_temp")
     * @param mixed $value Zu setzender Wert
     *                    Bool: true/false oder "ON"/"OFF"
     *                    Int/Float: Numerischer Wert
     *                    String: Textwert
     *                    Array: Wird ignoriert
     *
     * @return void
     *
     * @throws InvalidArgumentException Bei ungültiger Wertkonvertierung
     *
     * @example
     * // States
     * SetValue("state", "ON");         // Setzt bool true
     * SetValue("stateL1", false);      // Setzt "OFF"
     *
     * // Farben & Temperatur
     * SetValue("color_temp", 4000);     // Setzt Farbtemp + Kelvin
     * SetValue("color", 0xFF0000);     // Setzt Rot
     *
     * // Profile
     * SetValue("mode", "auto");        // Nutzt Profilzuordnung
     */
    protected function SetValue($ident, $value)
    {
        if (!$this->HasActiveParent()) {
            return;
        }

        $id = @$this->GetIDForIdent($ident);
        if (!$id) {
            $this->SendDebug(__FUNCTION__, 'Variable nicht gefunden: ' . $ident, 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'Verarbeite Variable: ' . $ident . ' mit Wert: ' . json_encode($value), 0);

        if (is_array($value)) {
            $this->SendDebug(__FUNCTION__, 'Wert ist ein Array, übersprungen: ' . $ident, 0);
            return;
        }

        $adjustedValue = $this->adjustValueByType($id, $value);
        $varType = IPS_GetVariable($id)['VariableType'];

        // Profilverarbeitung nur für nicht-boolesche Werte
        if ($varType !== 0) {
            $profileName = IPS_GetVariable($id)['VariableCustomProfile'];
            if ($profileName && IPS_VariableProfileExists($profileName)) {
                $profileAssociations = IPS_GetVariableProfile($profileName)['Associations'];
                foreach ($profileAssociations as $association) {
                    if ($association['Name'] == $value) {
                        $adjustedValue = $association['Value'];
                        $this->SendDebug(__FUNCTION__, 'Profilwert gefunden: ' . $value . ' -> ' . $adjustedValue, 0);
                        parent::SetValue($ident, $adjustedValue);
                        return;
                    }
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'Setze Variable: ' . $ident . ' auf Wert: ' . json_encode($adjustedValue), 0);
        parent::SetValue($ident, $adjustedValue);

        // Spezialbehandlung für ColorTemp
        if ($ident === 'color_temp') {
            $kelvinIdent = 'color_temp_kelvin';
            $kelvinValue = $this->convertMiredToKelvin($value);
            $this->SetValueDirect($kelvinIdent, $kelvinValue);
        }
    }

    /**
     * Setzt den Wert einer Variable direkt ohne weitere Verarbeitung.
     *
     * Diese Methode setzt den Wert einer Variable direkt mit minimaler Verarbeitung:
     * - Keine Profile-Verarbeitung
     * - Keine Spezialbehandlung von States
     * - Basale Typkonvertierung für grundlegende Datentypen
     *
     * Verarbeitung:
     * 1. Array-Werte werden zu JSON konvertiert
     * 2. Grundlegende Typkonvertierung (bool, int, float, string)
     * 3. Debug-Ausgaben für Fehleranalyse
     *
     * @param string $ident Der Identifikator der Variable, deren Wert gesetzt werden soll
     * @param mixed $value Der zu setzende Wert
     *                    - Array: Wird zu JSON konvertiert
     *                    - Bool: Wird zu bool konvertiert
     *                    - Int/Float: Wird zum entsprechenden Typ konvertiert
     *                    - String: Wird zu string konvertiert
     *
     * @return void
     *
     * @throws Exception Wenn SetValue fehlschlägt (wird intern behandelt)
     *
     * @internal Diese Methode wird hauptsächlich intern verwendet für:
     *          - Direkte Wertzuweisung ohne Profile
     *          - Array zu JSON Konvertierung
     *          - Debug-Werte setzen
     *
     * @example
     * // Boolean setzen
     * SetValueDirect("state", true);
     *
     * // Array als JSON
     * SetValueDirect("data", ["temp" => 22]);
     */
    protected function SetValueDirect($ident, $value): void
    {
        $this->SendDebug(__FUNCTION__ . ': Zu behandelnder Ident: ', $ident, 0);
        $variableID = @$this->GetIDForIdent($ident);

        if (!$variableID) {
            $this->SendDebug(__FUNCTION__, 'Variable nicht gefunden: ' . $ident, 0);
            return;
        }

        // Variablentyp ermitteln
        $varType = IPS_GetVariable($variableID)['VariableType'];

        // Typ-Prüfung und Konvertierung
        if (is_array($value)) {
            $this->SendDebug(__FUNCTION__, 'Array-Wert erkannt, konvertiere zu JSON', 0);
            $value = json_encode($value);
        }

        // Wert entsprechend Variablentyp konvertieren
        switch($varType) {
            case VARIABLETYPE_BOOLEAN:
                $value = boolval($value);
                break;
            case VARIABLETYPE_INTEGER:
                $value = intval($value);
                break;
            case VARIABLETYPE_FLOAT:
                $value = floatval($value);
                break;
            case VARIABLETYPE_STRING:
                $value = strval($value);
                break;
        }

        $this->SendDebug(__FUNCTION__, sprintf('Setze Variable: %s, Typ: %d, Wert: %s', $ident, $varType, json_encode($value)), 0);
        // Setze den Wert der Variable
        try {
            parent::SetValue($ident, $value);
        } catch (Exception $e) {
            $this->SendDebug(__FUNCTION__, 'Fehler: ' . $e->getMessage(), 0);
        }
    }

    /**
     * Fügt den übergebenen Payload-Daten die entsprechenden Variablentypen hinzu.
     * Diese Methode durchläuft die übergebenen Payload-Daten, prüft, ob die zugehörige
     * Variable existiert, und fügt den Variablentyp als neuen Schlüssel-Wert-Paar hinzu.
     *
     * Beispiel:
     * Wenn der Key 'temperature' vorhanden ist und die zugehörige Variable existiert, wird
     * ein neuer Eintrag 'temperature_type' hinzugefügt, der den Typ der Variable enthält.
     *
     * @param array $Payload Assoziatives Array mit den Payload-Daten.
     *
     * @return array Das modifizierte Payload-Array mit den hinzugefügten Variablentypen.
     */
    protected function AppendVariableTypes($Payload)
    {
        // Zeige das eingehende Payload im Debug
        $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Eingehendes Payload: ', json_encode($Payload), 0);

        foreach ($Payload as $key => $value) {
            // Konvertiere den Key in einen Variablen-Ident
            $ident = $key;

            // Prüfe, ob die Variable existiert
            $objectID = @$this->GetIDForIdent($ident);
            // $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Variable existiert für Ident: ', $ident, 0);
            if ($objectID) {
                // Hole den Typ der existierenden Variablen
                $variableType = IPS_GetVariable($objectID)['VariableType'];

                // Füge dem Payload den Variablentyp als neuen Schlüssel hinzu
                $Payload[$key . '_type'] = $variableType;
            }
        }

        // Zeige das modifizierte Payload im Debug
        $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: modifizierter Payload mit Typen: ', json_encode($Payload), 0);

        // Gib das modifizierte Payload zurück
        return $Payload;
    }

    /**
     * Holt oder registriert eine Variable basierend auf dem Identifikator.
     *
     * Diese Methode prüft, ob eine Variable mit dem angegebenen Identifikator existiert. Wenn nicht,
     * wird die Variable registriert und die ID der neu registrierten Variable zurückgegeben.
     *
     * @param string $ident Der Identifikator der Variable.
     * @param array|null $variableProps Die Eigenschaften der Variable, die registriert werden sollen, falls sie nicht existiert.
     * @param string|null $formattedLabel Das formatierte Label der Variable, falls vorhanden.
     * @return int|false Die ID der Variable oder false, wenn die Registrierung fehlschlägt.
     */
    private function getOrRegisterVariable($ident, $variableProps = null, $formattedLabel = null)
    {
        // Während Migration keine Variablen erstellen
        if($this->GetBuffer(self::BUFFER_KEYS['PROCESSING_MIGRATION']) === 'true') {
            return false;
        }

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = isset($backtrace[1]['function']) ? $backtrace[1]['function'] : 'unknown';

        $this->SendDebug(__FUNCTION__, 'Aufruf von getOrRegisterVariable für Ident: ' . $ident . ' von Funktion: ' . $caller, 0);

        $variableID = @$this->GetIDForIdent($ident);
        if (!$variableID && $variableProps !== null) {
            $this->SendDebug(__FUNCTION__, 'Variable nicht gefunden, Registrierung: ' . $ident, 0);
            $this->registerVariable($variableProps, $formattedLabel);
            $variableID = @$this->GetIDForIdent($ident);
            if (!$variableID) {
                $this->SendDebug(__FUNCTION__, 'Fehler beim Registrieren der Variable: ' . $ident, 0);
                return false;
            }
        }
        $this->SendDebug(__FUNCTION__, 'Variable gefunden: ' . $ident . ' (ID: ' . $variableID . ')', 0);
        return $variableID;
    }

    /**
     * Verarbeitet eine einzelne Variable aus dem empfangenen Payload.
     *
     * Diese Methode wird aufgerufen, um eine einzelne Variable aus dem empfangenen Payload zu verarbeiten.
     * Sie prüft, ob die Variable bekannt ist, registriert sie gegebenenfalls und setzt den Wert.
     *
     * @param string $key Der Schlüssel im empfangenen Payload.
     * @param mixed $value Der Wert, der mit dem Schlüssel verbunden ist.
     * @param array $payload Das gesamte empfangene Payload.
     * @param array $knownVariables Eine Liste der bekannten Variablen, die zur Verarbeitung verwendet werden.
     * @return void
     */
    private function processVariable($key, $value, $knownVariables)
    {
        $lowerKey = strtolower($key);
        $ident = $key;

        // Prüfe zuerst, ob eine Variable mit diesem Ident in Symcon existiert
        $variableID = @$this->GetIDForIdent($ident);
        if ($variableID) {
            $this->SendDebug(__FUNCTION__, 'Existierende Variable gefunden: ' . $ident, 0);
            $this->SetValue($ident, $value);
            return;
        }

        // Wenn keine existierende Variable gefunden wurde, prüfe auf bekannte Variablen aus JSON
        if (!array_key_exists($lowerKey, $knownVariables)) {
            $this->SendDebug(__FUNCTION__, 'Variable weder in Symcon noch in JSON bekannt, übersprungen: ' . $key, 0);
            return;
        }

        // Restliche Logik für neue Variablen aus JSON...
        $variableProps = $knownVariables[$lowerKey];

        // Spezielle Behandlung für Brightness in Lichtgruppen
        foreach (self::$VariableUseStandardProfile as $profile) {
            if ($profile['feature'] === $lowerKey &&
                isset($profile['group_type']) &&
                $profile['group_type'] === 'light' &&
                isset($variableProps['group_type']) &&
                $variableProps['group_type'] === 'light') {

                $this->SendDebug(__FUNCTION__, 'Brightness in Lichtgruppe gefunden - StandardProfile', 0);
                if ($this->processSpecialVariable($key, $value)) {
                    return;
                }
            }
        }

        // Voltage-Spezialbehandlung
        if ($lowerKey === 'voltage') {
            $this->SendDebug(__FUNCTION__, 'Voltage vor Konvertierung: ' . $value, 0);
            if ($this->processSpecialVariable($key, $value)) {
                return;
            }
        }

        // Variable registrieren und ID abrufen
        $variableID = $this->getOrRegisterVariable($ident, $variableProps);
        if (!$variableID) {
            return;
        }

        // Überprüfen, ob der Wert ein Array ist und entsprechend behandeln
        if (is_array($value)) {
            $this->SendDebug(__FUNCTION__, 'Wert ist ein Array, spezielle Behandlung für: ' . $key, 0);
            $this->SendDebug(__FUNCTION__, 'Array-Inhalt: ' . json_encode($value), 0);

            // Spezielle Behandlung für Farbwerte
            if (strpos($ident, 'color') === 0) {
                $this->handleColorVariable($ident, $value);
            }

            return;
        }

        // Voltage-Spezialbehandlung hinzufügen
        if ($lowerKey === 'voltage') {
            $this->SendDebug(__FUNCTION__, 'Voltage vor Konvertierung: ' . $value, 0);
            $value = $this->convertMillivoltToVolt($value);
            $this->SendDebug(__FUNCTION__, 'Voltage nach Konvertierung: ' . $value, 0);
        }

        // Wert setzen
        $this->SetValue($ident, $value);
    }

    /**
     * Verarbeitet Standard-Variablenaktionen.
     *
     * Diese Methode wird aufgerufen, wenn eine Aktion für eine Standard-Variable angefordert wird.
     * Sie konvertiert den Wert bei Bedarf und sendet den entsprechenden Set-Befehl.
     *
     * @param string $ident Der Identifikator der Standard-Variable.
     * @param mixed $value Der Wert, der mit der Standard-Variablen-Aktionsanforderung verbunden ist.
     * @return bool Gibt true zurück, wenn die Aktion erfolgreich verarbeitet wurde, andernfalls false.
     */
    private function handleStandardVariable($ident, $value): bool
    {
        $variableID = $this->getOrRegisterVariable($ident);
        if (!$variableID) {
            return false;
        }

        // Konvertiere boolesche Werte zu "ON"/"OFF"
        if (is_bool($value)) {
            $value = $value ? 'ON' : 'OFF';
        }
        // light-Brightness kann immer das Profil ~Intensity.100 haben
        if ($ident === 'brightness') {
            // Konvertiere 0-100 zurück zu Gerätebereich
            $max_brightness = $this->getBrightnessMaxValue(); // Aus Expose-Daten holen
            $deviceValue = $this->normalizeValueToRange($value, 0, 100, 0, $max_brightness);
            $payload = ['brightness' => $deviceValue];
            $this->SendSetCommand($payload);
            return true;
        }

        // Erstelle das Payload
        $payload = [$ident => $value];
        $this->SendDebug(__FUNCTION__, 'Sende payload: ' . json_encode($payload), 0);

        // Sende den Set-Befehl
        $this->SendSetCommand($payload);
        return true;
    }

    /**
     * Verarbeitet State-bezogene Aktionen und sendet entsprechende MQTT-Befehle.
     *
     * Diese Methode überprüft verschiedene State-Szenarien:
     * 1. Standard State-Pattern (ON/OFF)
     * 2. Vordefinierte States aus stateDefinitions
     * 3. States aus dem STATE_PATTERN
     *
     * @param string $ident Identifikator der State-Variable
     * @param mixed $value Zu setzender Wert (bool|string|int)
     *
     * @return bool True wenn State erfolgreich verarbeitet wurde, sonst False
     *
     * @example
     * // Einfacher ON/OFF State
     * handleStateVariable("state", true); // Sendet ON
     * handleStateVariable("state", false); // Sendet OFF
     */
    private function handleStateVariable(string $ident, $value): bool
    {
        $this->SendDebug(__FUNCTION__, "State-Handler für: $ident mit Wert: " . json_encode($value), 0);

        // Prüfe auf Standard-State Pattern und konvertiere zu MQTT-Payload-Key
        if (preg_match(self::STATE_PATTERN['SYMCON'], $ident)) {
            $payload = [$ident => $value ? 'ON' : 'OFF'];
            $this->SendDebug(__FUNCTION__, "State-Payload wird gesendet: " . json_encode($payload), 0);
            $this->SendSetCommand($payload);
            $this->SetValueDirect($ident, $value ? 'ON' : 'OFF');
            return true;
        }

        // Prüfe auf vordefinierte States
        if (isset(static::$stateDefinitions[$ident])) {
            $stateInfo = static::$stateDefinitions[$ident];
            if (isset($stateInfo['values'])) {
                $index = is_bool($value) ? (int)$value : $value;
                if (isset($stateInfo['values'][$index])) {
                    $payload = [$ident => $stateInfo['values'][$index]];
                    $this->SendDebug(__FUNCTION__, "Vordefinierter State-Payload wird gesendet: " . json_encode($payload), 0);
                    $this->SendSetCommand($payload);
                    $this->SetValueDirect($ident, $stateInfo['values'][$index]);
                    return true;
                }
            }
        }

        // Überprüfen, ob der Wert in STATE_PATTERN definiert ist
        if (array_key_exists(strtoupper($value), self::STATE_PATTERN)) {
            $adjustedValue = self::STATE_PATTERN[strtoupper($value)];
            $this->SendDebug(__FUNCTION__, "State-Wert gefunden: " . $value . " -> " . json_encode($adjustedValue), 0);
            $this->SetValueDirect($ident, $adjustedValue);
            return true;
        }

        $this->SendDebug(__FUNCTION__, "Kein passender State-Handler gefunden", 0);
        return false;
    }

    /**
     * Verarbeitet Farbvariablenaktionen.
     *
     * Diese Methode wird aufgerufen, wenn eine Aktion für eine Farbvariable angefordert wird.
     * Sie verarbeitet verschiedene Arten von Farbvariablen basierend auf dem Identifikator der Variable.
     *
     * @param string $ident Der Identifikator der Farbvariable.
     * @param mixed $value Der Wert, der mit der Farbvariablen-Aktionsanforderung verbunden ist.
     * @return bool Gibt true zurück, wenn die Aktion erfolgreich verarbeitet wurde, andernfalls false.
     */
    private function handleColorVariable($ident, $value): bool
    {
        $handled = match ($ident) {
            'color' => function() use ($value) {
                $this->SendDebug(__FUNCTION__, 'Color Value: ' . json_encode($value), 0);
                if (is_int($value)) {
                    // Umrechnung des Integer-Werts in x und y
                    $xy = $this->HexToXY($value);
                    $payload = [
                        'color' => [
                            'x' => $xy['x'],
                            'y' => $xy['y']
                        ],
                        'brightness' => 255 // Beispielwert für Helligkeit
                    ];
                    $this->SendSetCommand($payload);
                } elseif (is_array($value)) {
                    // Prüfen auf x/y Werte im color Array
                    if (isset($value['color']) && isset($value['color']['x']) && isset($value['color']['y'])) {
                        $brightness = $value['brightness'] ?? 255;
                        $this->SendDebug(__FUNCTION__, 'Processing color with brightness: ' . $brightness, 0);

                        // Umrechnung der x und y Werte in einen HEX-Wert mit Helligkeit
                        $hexValue = $this->XYToHex($value['color']['x'], $value['color']['y'], $brightness);
                        $this->SetValueDirect('color', $hexValue);
                    } elseif (isset($value['x']) && isset($value['y'])) {
                        // Direkte x/y Werte
                        $brightness = $value['brightness'] ?? 255;
                        $hexValue = $this->XYToHex($value['x'], $value['y'], $brightness);
                        $this->SetValueDirect('color', $hexValue);
                    }
                } else {
                    $this->SendDebug(__FUNCTION__, 'Ungültiger Wert für color: ' . json_encode($value), 0);
                    return false;
                }
                return true;
            },
            'color_hs' => function() use ($value) {
                $this->SendDebug(__FUNCTION__, 'Color HS', 0);
                $this->setColor($value, 'hs');
                return true;
            },
            'color_rgb' => function() use ($value) {
                $this->SendDebug(__FUNCTION__, 'Color RGB', 0);
                $this->setColor($value, 'cie', 'color_rgb');
                return true;
            },
            'color_temp_kelvin' => function() use ($value) {
                // Konvertiere Kelvin zu Mired
                $convertedValue = $this->convertKelvinToMired($value);
                $payloadKey = 'color_temp'; // Zigbee2MQTT erwartet immer color_temp als Key
                $payload = [$payloadKey => $convertedValue];

                // Debug Ausgabe
                $this->SendDebug(__FUNCTION__, sprintf('Converting %dK to %d Mired', $value, $convertedValue), 0);

                // Sende Payload an Gerät
                $this->SendSetCommand($payload);

                // Aktualisiere auch die Mired-Variable
                $this->SetValueDirect('color_temp', $convertedValue);

                return true;
            },
            'color_temp' => function() use ($value) {
                $convertedValue = $this->convertKelvinToMired($value);
                $this->SendDebug(__FUNCTION__, 'Converted Color Temp: ' . $convertedValue, 0);
                $payload = ['color_temp' => $convertedValue];
                $this->SendSetCommand($payload);
                return true;
            },
            default => function() {
                return false;
            },
        };

        return $handled();
    }

    /**
     * Verarbeitet Preset-Variablenaktionen.
     *
     * Diese Methode wird aufgerufen, wenn eine Aktion für eine Preset-Variable angefordert wird.
     * Sie leitet die Aktion an die Hauptvariable weiter und sendet den entsprechenden Set-Befehl.
     *
     * @param string $ident Der Identifikator der Preset-Variable.
     * @param mixed $value Der Wert, der mit der Preset-Aktionsanforderung verbunden ist.
     * @return bool Gibt true zurück, wenn die Aktion erfolgreich verarbeitet wurde, andernfalls false.
     */
    private function handlePresetVariable($ident, $value)
    {
        // Extrahiere den Identifikator der Hauptvariable
        $mainIdent = str_replace('_presets', '', $ident);
        $this->SendDebug(__FUNCTION__, "Aktion über presets erfolgt, Weiterleitung zur eigentlichen Variable: $mainIdent", 0);
        $this->SendDebug(__FUNCTION__, "Aktion über presets erfolgt, Schreibe zur PresetVariable Variable: $ident", 0);

        // Setze den Wert der Hauptvariable
        $this->SetValue($mainIdent, $value);
        $this->SetValue($ident, $value);

        $payload = [$mainIdent => $value];
        $this->SendSetCommand($payload);

        // Aktualisiere die Preset-Variable
        $this->SetValueDirect($ident, $value);

        // Aktualisiere die Farbtemperatur-Kelvin-Variable, wenn die Preset-Variable für Farbtemperatur geändert wird
        if ($mainIdent === 'color_temp') {
            $kelvinIdent = $mainIdent . '_kelvin';
            $kelvinValue = $this->convertMiredToKelvin($value);
            $this->SetValueDirect($kelvinIdent, $kelvinValue);
        }

        return true;
    }

    /**
     * Verarbeitet String-Variablen, die keine Rückmeldung von Zigbee2MQTT erfordern.
     *
     * Diese Methode wird aufgerufen, wenn eine Aktion für eine String-Variable angefordert wird,
     * die keine Rückmeldung von Zigbee2MQTT erfordert. Sie sendet den entsprechenden Set-Befehl
     * und aktualisiert die Variable direkt.
     *
     * @param string $ident Der Identifikator der String-Variable.
     * @param mixed $value Der Wert, der mit der String-Variablen-Aktionsanforderung verbunden ist.
     * @return bool Gibt true zurück, wenn die Aktion erfolgreich verarbeitet wurde, andernfalls false.
     */
    private function handleStringVariableNoResponse($ident, $value)
    {
        if (in_array($ident, self::$stringVariablesNoResponse)) {
            $this->SendDebug(__FUNCTION__, 'Behandlung String ohne Rückmeldung: ' . $ident, 0);
            $payload = [$ident => $value];
            $this->SendSetCommand($payload);
            $this->SetValue($ident, $value);
            return true;
        }
        return false;
    }

    /**
     * Passt den Wert basierend auf dem Variablentyp an.
     *
     * Diese Methode konvertiert den übergebenen Wert in den entsprechenden Typ der Variable.
     *
     * @param int $variableID Die ID der Variable, deren Typ bestimmt werden soll.
     * @param mixed $value Der Wert, der angepasst werden soll.
     * @return mixed Der angepasste Wert basierend auf dem Variablentyp.
     */
    private function adjustValueByType($variableID, $value)
    {
        $varType = IPS_GetVariable($variableID)['VariableType'];
        $this->SendDebug(__FUNCTION__, 'Variable ID: ' . $variableID . ', Typ: ' . $varType . ', Ursprünglicher Wert: ' . json_encode($value), 0);

        switch ($varType) {
            case 0: // Boolean
                if (is_bool($value)) {
                    $this->SendDebug(__FUNCTION__, 'Wert ist bereits bool: ' . json_encode($value), 0);
                    return $value;
                }
                if (is_string($value)) {
                    if (strtoupper($value) === 'ON') {
                        $this->SendDebug(__FUNCTION__, 'Konvertiere "ON" zu true', 0);
                        return true;
                    } elseif (strtoupper($value) === 'OFF') {
                        $this->SendDebug(__FUNCTION__, 'Konvertiere "OFF" zu false', 0);
                        return false;
                    }
                }
                $this->SendDebug(__FUNCTION__, 'Konvertiere zu bool: ' . json_encode((bool)$value), 0);
                return (bool)$value;
            case 1:
                $this->SendDebug(__FUNCTION__, 'Konvertiere zu int: ' . (int)$value, 0);
                return (int)$value;
            case 2:
                $this->SendDebug(__FUNCTION__, 'Konvertiere zu float: ' . (float)$value, 0);
                return (float)$value;
            case 3:
                $this->SendDebug(__FUNCTION__, 'Konvertiere zu string: ' . (string)$value, 0);
                return (string)$value;
            default:
                $this->SendDebug(__FUNCTION__, 'Unbekannter Variablentyp für ID ' . $variableID . ', Wert: ' . json_encode($value), 0);
                return $value;
        }
    }

// Farbmanagement

    /**
     * Setzt die Farbe des Geräts basierend auf dem angegebenen Farbmodus.
     *
     * Diese Methode unterstützt verschiedene Farbmodi und konvertiert die Farbe in das entsprechende Format,
     * bevor sie an das Gerät gesendet wird. Unterstützte Modi sind:
     * - **cie**: Konvertiert RGB in den XY-Farbraum (CIE 1931).
     * - **hs**: Verwendet den Hue-Saturation-Modus (HS), um die Farbe zu setzen.
     * - **hsl**: Nutzt den Farbton, Sättigung und Helligkeit (HSL), um die Farbe zu setzen.
     * - **hsv**: Nutzt den Farbton, Sättigung und den Wert (HSV), um die Farbe zu setzen.
     *
     * @param int $color Der Farbwert in Hexadezimal- oder RGB-Format.
     *                   Die Farbe wird intern in verschiedene Farbmodelle umgerechnet.
     * @param string $mode Der Farbmodus, der verwendet werden soll. Unterstützte Werte:
     *                     - 'cie': Konvertiert die RGB-Werte in den XY-Farbraum.
     *                     - 'hs': Verwendet den Hue-Saturation-Modus.
     *                     - 'hsl': Nutzt den HSL-Modus für die Umrechnung.
     *                     - 'hsv': Nutzt den HSV-Modus für die Umrechnung.
     * @param string $Z2MMode Der Zigbee2MQTT-Modus, standardmäßig 'color'. Kann auch 'color_rgb' sein.
     *                        - 'color': Setzt den Farbwert im XY-Farbraum.
     *                        - 'color_rgb': Setzt den Farbwert im RGB-Modus (nur für 'cie' relevant).
     *
     * @return void
     *
     * @throws InvalidArgumentException Wenn der Modus ungültig ist.
     *
     * @example
     * // Setze eine Farbe im HSL-Modus.
     * $this->setColor(0xFF5733, 'hsl', 'color');
     *
     * // Setze eine Farbe im HSV-Modus.
     * $this->setColor(0x4287f5, 'hsv', 'color');
     */
    private function setColor(int $color, string $mode, string $Z2MMode = 'color')
    {
        $Payload = match ($mode) {
            'cie' => function() use ($color, $Z2MMode) {
                $RGB = $this->HexToRGB($color);
                $cie = $this->RGBToXy($RGB);

                if ($Z2MMode === 'color') {
                    // Entferne 'bri' aus dem 'color'-Objekt und füge es separat als 'brightness' hinzu
                    $brightness = $cie['bri'];
                    unset($cie['bri']);
                    return ['color' => $cie, 'brightness' => $brightness];
                } elseif ($Z2MMode === 'color_rgb') {
                    return ['color_rgb' => $cie];
                }
            },
            'hs' => function() use ($color, $Z2MMode) {
                $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: setColor - Input Color', json_encode($color), 0);

                $RGB = $this->HexToRGB($color);
                $HSB = $this->RGBToHSB($RGB[0], $RGB[1], $RGB[2]);

                $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: setColor - RGB Values for HSB Conversion', 'R: ' . $RGB[0] . ', G: ' . $RGB[1] . ', B: ' . $RGB[2], 0);

                if ($Z2MMode == 'color') {
                    return [
                        'color' => [
                            'hue'        => $HSB['hue'],
                            'saturation' => $HSB['saturation'],
                        ],
                        'brightness' => $HSB['brightness']
                    ];
                } else {
                    return null;
                }
            },
            'hsl' => function() use ($color, $Z2MMode) {
                $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: setColor - Input Color', json_encode($color), 0);

                $RGB = $this->HexToRGB($color);
                $HSL = $this->RGBToHSL($RGB[0], $RGB[1], $RGB[2]);

                $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: setColor - RGB Values for HSL Conversion', 'R: ' . $RGB[0] . ', G: ' . $RGB[1] . ', B: ' . $RGB[2], 0);

                if ($Z2MMode == 'color') {
                    return [
                        'color' => [
                            'hue'        => $HSL['hue'],
                            'saturation' => $HSL['saturation'],
                            'lightness'  => $HSL['lightness']
                        ]
                    ];
                } else {
                    return null;
                }
            },
            'hsv' => function() use ($color, $Z2MMode) {
                $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: setColor - Input Color', json_encode($color), 0);

                $RGB = $this->HexToRGB($color);
                $HSV = $this->RGBToHSV($RGB[0], $RGB[1], $RGB[2]);

                $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: setColor - RGB Values for HSV Conversion', 'R: ' . $RGB[0] . ', G: ' . $RGB[1] . ', B: ' . $RGB[2], 0);

                if ($Z2MMode == 'color') {
                    return [
                        'color' => [
                            'hue'        => $HSV['hue'],
                            'saturation' => $HSV['saturation'],
                        ],
                        'brightness' => $HSV['brightness']
                    ];
                } else {
                    return null;
                }
            },
            default => throw new InvalidArgumentException('Invalid color mode: ' . $mode),
        };

        if ($Payload !== null) {
            $this->SendSetCommand($Payload());
        }
    }

// Spezialvariablen & Konvertierung

    /**
     * Verarbeitet spezielle Variablen mit besonderen Anforderungen
     *
     * Verarbeitungsschritte:
     * 1. Prüft ob Variable in specialVariables definiert
     * 2. Konvertiert Property zu Ident und Label
     * 3. Registriert Variable falls nicht vorhanden
     * 4. Passt Wert entsprechend Variablentyp an
     * 5. Setzt Wert mit Debug-Ausgaben
     *
     * @param string $key Name der zu verarbeitenden Property
     * @param mixed $value Zu setzender Wert
     *                    Kann sein:
     *                    - String: Direkter Wert
     *                    - Array: Wird konvertiert
     *                    - Bool: Wird angepasst
     *                    - Int/Float: Wird skaliert
     *
     * @return bool True wenn Variable verarbeitet wurde,
     *              False wenn keine Spezialvariable
     *
     * @throws Exception Bei Fehlern in der Variablenregistrierung
     *
     * @internal Verwendet von:
     *          - processPayload()
     *          - handleSpecialCases()
     *
     * @example
     * // Verarbeitet Farbtemperatur
     * processSpecialVariable("color_temp", 4000);
     *
     * // Verarbeitet RGB-Farbe
     * processSpecialVariable("color", ["r" => 255, "g" => 0, "b" => 0]);
     */
    private function processSpecialVariable($key, $value)
    {
        if (!isset(self::$specialVariables[$key])) {
            return false;
        }

        $variableProps = ['property' => $key];
        $ident = $key;
        $formattedLabel = $this->convertLabelToName($key);
        $variableID = $this->getOrRegisterVariable($ident, $variableProps, $formattedLabel);

        if (!$variableID) {
            return true;
        }

        // Spezielle Verarbeitung für die Variable
        $adjustedValue = $this->adjustSpecialValue($ident, $value);

        // Debug-Ausgabe des verarbeiteten Wertes
        $debugValue = is_array($adjustedValue) ? json_encode($adjustedValue) : $adjustedValue;
        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: ', $key . ' verarbeitet: ' . $key . ' => ' . $debugValue, 0);

        // Wert setzen
        $this->SetValueDirect($ident, $adjustedValue);

        $this->SendDebug(__FUNCTION__, sprintf('SetValueDirect aufgerufen für %s mit Wert: %s (Typ: %s)', $ident, is_array($adjustedValue) ? json_encode($adjustedValue) : $adjustedValue, gettype($adjustedValue)), 0);
        return true;
    }

    /**
     * Verarbeitet spezielle Variablen und setzt deren Wert direkt.
     *
     * Diese Methode wird aufgerufen, um spezielle Variablen zu verarbeiten und deren Wert direkt zu setzen.
     * Sie registriert die Variable, falls sie noch nicht existiert, und führt spezifische Konvertierungen
     * und Anpassungen basierend auf dem Identifikator der Variable durch.
     *
     * @param string $key Der Schlüssel der speziellen Variable.
     * @param mixed $value Der Wert, der mit der speziellen Variablen verbunden ist.
     * @return void
     */
    private function handleSpecialVariable($key, $value)
    {
        $variableProps = ['property' => $key];
        $ident = $key;
        $formattedLabel = $this->convertLabelToName($key);
        $variableID = $this->getOrRegisterVariable($ident, $variableProps, $formattedLabel);

        if (!$variableID) {
            return;
        }

        // Spezielle Verarbeitung für die Variable
        $adjustedValue = $this->processSpecialVariable($ident, $value);

        // Debug-Ausgabe des verarbeiteten Wertes
        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: ' . $key . ' verarbeitet: ' . $key . ' => ' . $adjustedValue, 0);

        // Wert setzen
        $this->SetValueDirect($ident, $adjustedValue);

        $this->SendDebug(__FUNCTION__, 'SetValueDirect aufgerufen für ' . $ident . ' mit Wert: ' . $adjustedValue, 0);
    }

    /**
     * Passt den Wert spezieller Variablen entsprechend ihrer Anforderungen an
     *
     * Verarbeitungsschritte:
     * 1. Debug-Ausgabe des Eingangswerts
     * 2. Spezifische Konvertierung je nach Variablentyp
     * 3. Debug-Ausgabe des konvertierten Werts
     *
     * Unterstützte Variablentypen:
     * - last_seen: Konvertiert Millisekunden zu Sekunden
     * - color_mode: Wandelt Farbmodus in Großbuchstaben (hs->HS, xy->XY)
     * - color_temp_kelvin: Rechnet Kelvin in Mired um (1.000.000/K)
     *
     * @param string $ident Identifikator der Variable (last_seen, color_mode, color_temp_kelvin)
     * @param mixed $value Zu konvertierender Wert
     *                    - LastSeen: Integer (Millisekunden)
     *                    - ColorMode: String (hs, xy)
     *                    - ColorTempKelvin: Integer (2000-6500K)
     *
     * @return mixed Konvertierter Wert
     *               - LastSeen: Integer (Sekunden)
     *               - ColorMode: String (HS, XY)
     *               - ColorTempKelvin: String (Mired)
     *               - Default: Originalwert
     *
     * @example
     * // LastSeen konvertieren
     * adjustSpecialValue("last_seen", 1600000000000); // Returns: 1600000000
     *
     * // ColorMode konvertieren
     * adjustSpecialValue("color_mode", "hs"); // Returns: "HS"
     *
     * // Kelvin zu Mired
     * adjustSpecialValue("color_temp_kelvin", 4000); // Returns: "250"
     */
    private function adjustSpecialValue($ident, $value)
    {
        $debugValue = is_array($value) ? json_encode($value) : $value;
        $this->SendDebug(__FUNCTION__, 'Processing special variable: ' . $ident . ' with value: ' . $debugValue, 0);        switch ($ident) {
            case 'last_seen':
                // Umrechnung von Millisekunden auf Sekunden
                $adjustedValue = intval($value / 1000);
                $this->SendDebug(__FUNCTION__, 'Converted value: ' . $adjustedValue, 0);
                return $adjustedValue;
            case 'color_mode':
                // Konvertierung von 'hs' zu 'HS' und 'xy' zu 'XY'
                $adjustedValue = strtoupper($value);
                $this->SendDebug(__FUNCTION__, 'Converted value: ' . $adjustedValue, 0);
                return $adjustedValue;
            case 'color_temp_kelvin':
                // Umrechnung von Kelvin zu Mired
                $adjustedValue = strval(intval(round(1000000 / $value, 0)));
                $this->SendDebug(__FUNCTION__, 'Converted value: ' . $adjustedValue, 0);
                return $adjustedValue;
            case 'brightness':
                // Konvertiere auf 0-100 Skala
                $max_brightness = $this->getBrightnessMaxValue();
                $adjustedValue = $this->normalizeValueToRange($value, 0, $max_brightness, 0, 100);
                $this->SendDebug(__FUNCTION__, 'Converted brightness value: ' . $adjustedValue, 0);
                return $adjustedValue;
            case 'voltage':
                // Konvertiere mV zu V
                $adjustedValue = $this->convertMillivoltToVolt($value);
                $this->SendDebug(__FUNCTION__, 'Converted voltage value: ' . $adjustedValue, 0);
                return $adjustedValue;
                default:
                return $value;
        }
    }

    /**
     * Konvertiert Millivolt in Volt, wenn der Wert größer als 400 ist.
     *
     * @param float $value Der zu konvertierende Wert in Millivolt.
     * @return float Der konvertierte Wert in Volt.
     */
    private function convertMillivoltToVolt($value)
    {
        if ($value > 400) { // Werte über 400 sind in mV
            return $value * 0.001; // Umrechnung von mV in V mit Faktor 0.001
        }
        return $value; // Werte <= 400 sind bereits in V
    }

    /**
     * Konvertiert ein Label in einen formatierten Namen mit Großbuchstaben am Wortanfang
     * und behält bestimmte Abkürzungen in Großbuchstaben. Speichert den konvertierten Namen in einer JSON-Datei.
     *
     * @param string $label Das zu formatierende Label
     * @return string Das formatierte Label
     */
    private function convertLabelToName(string $label): string
    {
        // Liste von Abkürzungen die in Großbuchstaben bleiben sollen
        $upperCaseWords = ['HS', 'RGB', 'XY', 'HSV', 'HSL', 'LED'];

        // Ersetze Unterstriche durch Leerzeichen
        $words = str_replace('_', ' ', $label);

        // Konvertiere jeden Wortanfang in Großbuchstaben
        $words = ucwords($words);

        // Ersetze bekannte Abkürzungen durch ihre Großbuchstaben-Version
        foreach ($upperCaseWords as $upperWord) {
            $words = str_ireplace(
                [" $upperWord", " " . ucfirst(strtolower($upperWord))],
                " $upperWord",
                $words
            );
        }

        $this->SendDebug(__FUNCTION__, 'Converted Label: ' . $words, 0);

        // Prüfe, ob der Name in der locale.json vorhanden ist
        if (!$this->isValueInLocaleJson($words)) {
            // Füge den Namen zur translations.json hinzu
            $this->addValueToTranslationsJson($words);
        }

        return $words;
    }

// Profilmanagement

    /**
     * Registriert ein Variablenprofil basierend auf dem Expose-Typ oder einem optionalen State-Mapping.
     *
     * @param array $expose Die Expose-Daten mit folgenden Schlüsseln:
     *                     - 'type': Typ des Exposes (binary, enum, numeric) (string)
     *                     - 'property' oder 'name': Name der Eigenschaft (string)
     *                     - 'value_on': Optional - Wert für "An"-Zustand bei binary (mixed)
     *                     - 'value_off': Optional - Wert für "Aus"-Zustand bei binary (mixed)
     *                     - 'values': Optional - Array möglicher Werte bei enum (array)
     *                     - 'unit': Optional - Einheit bei numeric (string)
     *                     - 'value_min': Optional - Minimaler Wert bei numeric (float|int)
     *                     - 'value_max': Optional - Maximaler Wert bei numeric (float|int)
     * @param array|null $stateMapping Optionales Mapping für spezifische Zustände
     *                                Format: ['state1' => 'value1', 'state2' => 'value2']
     *
     * @return string Name des erstellten/vorhandenen Profils
     *                - '~Switch' für Standard-Schalter
     *                - 'Z2M.[property]' für benutzerdefinierte Profile
     *                - Systemprofil-Name wenn verfügbar
     *
     * @throws \Exception Wenn ungültige Parameter übergeben werden
     *
     * @example
     * // Binary Switch
     * $expose = [
     *     'type' => 'binary',
     *     'property' => 'state',
     *     'value_on' => 'ON',
     *     'value_off' => 'OFF'
     * ];
     * $profile = $this->registerVariableProfile($expose);
     * // Ergebnis: '~Switch'
     */
    private function registerVariableProfile($expose, $stateMapping = null)
    {
        $type = $expose['type'] ?? '';
        $property = $expose['property'] ?? $expose['name'];
        $ProfileName = 'Z2M.' . strtolower($property);

        // Entferne das doppelte Präfix, falls vorhanden
        $ProfileName = str_replace('Z2M.Z2M_', 'Z2M.', $ProfileName);

        // State-Mapping prüfen
        $stateMapping = $this->handleStateMapping($ProfileName);
        if ($stateMapping !== null) {
            return $stateMapping;
        }

        // Standard-Profil prüfen
        if ($type === 'binary') {
            if (isset($expose['value_on']) && isset($expose['value_off'])) {
                $valueOn = $expose['value_on'];
                $valueOff = $expose['value_off'];

                // Prüfen, ob die Werte Strings sind, bevor strtoupper verwendet wird
                if (($valueOn === true && $valueOff === false) ||
                    ($valueOn === false && $valueOff === true) ||
                    (is_string($valueOn) && is_string($valueOff) &&
                     strtoupper($valueOn) === 'ON' && strtoupper($valueOff) === 'OFF')) {
                    return '~Switch';
                } else {
                    return $this->createCustomStringProfile($ProfileName, $valueOn, $valueOff);
                }
            }
            return '~Switch';
        }

        $standardProfile = $this->getStandardProfile($type, $property);
        if ($this->isValidStandardProfile($standardProfile)) {
            return $standardProfile;
        }

        // Typ-spezifisches Profil erstellen
        return $this->handleProfileType($type, $expose, $ProfileName);
    }

    /**
     * Holt das Standardprofil basierend auf Typ und Eigenschaft.
     *
     * Diese Methode sucht in den vordefinierten Standardprofilen (VariableUseStandardProfile)
     * nach einem passenden Profil für die übergebene Kombination aus Typ und Eigenschaft.
     *
     * @param string $type Der Typ des Exposes (z.B. 'binary', 'numeric', 'enum')
     * @param string $property Die Eigenschaft des Exposes (z.B. 'temperature', 'humidity')
     * @param string|null $groupType Optional - Spezifischer Gruppentyp für erweiterte Profilzuordnung
     *
     * @return string|null Der Name des Standardprofils oder null, wenn kein Standardprofil definiert ist
     *                     - '~Temperature' für Temperatur-Eigenschaften
     *                     - '~Humidity' für Feuchtigkeits-Eigenschaften
     *                     - '~Battery' für Batterie-Eigenschaften
     *                     - null wenn kein passendes Profil gefunden wurde
     *
     * @example
     * // Temperatur-Profil
     * $profile = $this->getStandardProfile('numeric', 'temperature');
     * // Ergebnis: '~Temperature'
     *
     * // Gruppen-spezifisches Profil
     * $profile = $this->getStandardProfile('binary', 'state', 'light');
     * // Ergebnis: '~Switch'
     */
    private function getStandardProfile(string $type, string $property, ?string $groupType = null): ?string
    {
        $this->SendDebug(__FUNCTION__, "Checking for standard profile with type: $type, property: $property, groupType: $groupType", 0);

        // Überprüfen, ob ein Standardprofil für den Typ und die Eigenschaft definiert ist
        foreach (self::$VariableUseStandardProfile as $entry) {
            $this->SendDebug(__FUNCTION__, "Checking entry: " . json_encode($entry), 0);
            if (isset($entry['type']) && ($entry['type'] === $type || $entry['type'] === '') && $entry['feature'] === $property) {
                $this->SendDebug(__FUNCTION__, "Found standard profile for type: $type, property: $property", 0);
                return $entry['profile'];
            }
            if (isset($entry['group_type']) && ($entry['group_type'] === $groupType || $entry['group_type'] === '') && $entry['feature'] === $property) {
                $this->SendDebug(__FUNCTION__, "Found standard profile for groupType: $groupType, property: $property", 0);
                return $entry['profile'];
            }
        }

        // Kein Standardprofil gefunden
        $this->SendDebug(__FUNCTION__, "No standard profile found for type: $type, property: $property, groupType: $groupType", 0);
        return null;
    }

    /**
     * Bestimmt den Variablentyp basierend auf verschiedenen Kriterien.
     *
     * @param string $type Der Expose-Typ (z.B. 'binary', 'numeric', 'enum', 'string', 'text', 'composite')
     * @param string $feature Name der Eigenschaft (z.B. 'state', 'brightness', 'temperature')
     * @param string $unit Optional - Die Einheit des Wertes (z.B. '°C', 'W', '%')
     * @param float $value_step Optional - Die Schrittweite für numerische Werte (Standard: 1.0)
     * @param string|null $groupType Optional - Gruppentyp für spezielle Mappings
     *
     * @return string Der ermittelte Variablentyp ('bool', 'int', 'float', 'string')
     *
     * @note Für 'numeric' Typen gilt folgende Logik:
     *       - Returns 'float' wenn:
     *         * Die Einheit in FLOAT_UNITS definiert ist (z.B. 'W', '°C', 'V')
     *         * value_step keine ganze Zahl ist (z.B. 0.5)
     *       - Returns 'int' wenn:
     *         * Keine der float-Bedingungen zutrifft
     *
     * @example
     * // Float Beispiel (Temperatur)
     * $type = $this->getVariableTypeFromProfile('numeric', 'temperature', '°C', 0.5);
     * // Ergebnis: 'float'
     *
     * // Integer Beispiel (Helligkeit)
     * $type = $this->getVariableTypeFromProfile('numeric', 'brightness', '%', 1.0);
     * // Ergebnis: 'int'
     */
    private function getVariableTypeFromProfile($type, string $feature, $unit = '', float $value_step = 1.0, ?string $groupType = null): string
    {
        // Erst StandardProfile prüfen
        foreach (self::$VariableUseStandardProfile as $profile) {
            if ($profile['feature'] === $feature) {
                switch ($profile['variableType']) {
                    case VARIABLETYPE_BOOLEAN:
                        return 'bool';
                    case VARIABLETYPE_INTEGER:
                        return 'int';
                    case VARIABLETYPE_FLOAT:
                        return 'float';
                    case VARIABLETYPE_STRING:
                        return 'string';
                }
            }
        }
        // Prüfen, ob ein spezifisches Mapping für type und feature existiert
        foreach (self::$VariableUseStandardProfile as $entry) {
            if ((isset($entry['type']) && ($entry['type'] === $type || $entry['type'] === '')) && $entry['feature'] === $feature) {
                $this->SendDebug(__FUNCTION__, 'Found specific mapping for type and feature: ' . $entry['variableType'], 0);
                return $entry['variableType'];
            }
            if ((isset($entry['group_type']) && ($entry['group_type'] === $groupType || $entry['group_type'] === '')) && $entry['feature'] === $feature) {
                $this->SendDebug(__FUNCTION__, 'Found specific mapping for group_type and feature: ' . $entry['variableType'], 0);
                return $entry['variableType'];
            }
        }

        // Prüfen, ob die Einheit in den Float-Einheiten enthalten ist
        if (!empty($unit) && is_string($unit)) {
            // Einheit in UTF-8 dekodieren
            $unit = mb_convert_encoding(mb_convert_encoding($unit, 'ISO-8859-1', 'UTF-8'), 'ISO-8859-1', 'UTF-8');            $unitTrimmed = str_replace(' ', '', $unit);
            if (in_array($unitTrimmed, self::FLOAT_UNITS, true)) {
                return 'float';
            }
        }

        // Zusätzliche Prüfung basierend auf value_step
        if (fmod($value_step, 1) !== 0.0) {
            $this->SendDebug(__FUNCTION__, 'Value step is not an integer, returning float', 0);
            return 'float';
        }

        // Allgemeines Typ-Mapping
        $typeMapping = [
            'binary'    => 'bool',
            'numeric'   => 'int',    // Standardmäßig 'numeric' auf 'int' abbilden
            'enum'      => 'string',
            'string'    => 'string',
            'text'      => 'string',
            'composite' => 'composite',
            // Weitere Mapping-Optionen hinzufügen
        ];

        $this->SendDebug(__FUNCTION__, 'Returning type from typeMapping: ' . ($typeMapping[$type] ?? 'string'), 0);
        return $typeMapping[$type] ?? 'string';
    }

    /**
     * Überprüft, ob ein Standardprofil gültig ist.
     *
     * Diese Methode überprüft, ob ein Standardprofil vergeben ist und ob es existiert.
     *
     * @param string|null $profile Der Name des Standardprofils.
     * @return bool Gibt true zurück, wenn das Standardprofil gültig ist, andernfalls false.
     */
    private function isValidStandardProfile(?string $profile): bool
    {
        // Überprüfen, ob das Profil nicht null und nicht leer ist
        if ($profile === null || $profile === '') {
            return false;
        }

        // Überprüfen, ob das Profil existiert
        if (IPS_VariableProfileExists($profile)) {
            return true;
        }

        // Überprüfen, ob es sich um ein Systemprofil handelt (beginnt mit '~')
        if (strpos($profile, '~') === 0) {
            return true;
        }

        return false;
    }

    /**
     * Handhabt die Erstellung eines Profils basierend auf dem Typ des Exposes.
     *
     * Diese Methode erstellt ein Profil für den angegebenen Expose-Typ (z. B. binary, enum, numeric).
     * Sie ruft die entsprechenden Methoden zur Erstellung des Profils auf.
     *
     * @param string $type Der Typ des Exposes (z. B. 'binary', 'enum', 'numeric').
     * @param array $expose Die Expose-Daten, die Informationen wie Typ, Werte und Einheiten enthalten.
     * @param string $ProfileName Der Name des zu erstellenden Profils.
     *
     * @return string Der Name des erstellten Profils.
     */
    private function handleProfileType(string $type, array $expose, string $ProfileName): string
    {
        $this->SendDebug(__FUNCTION__, 'Processing type: ' . $type . ' for profile: ' . $ProfileName, 0);
        $this->SendDebug(__FUNCTION__, 'Expose data: ' . json_encode($expose), 0);

        switch ($type) {
            case 'binary':
                return $this->createBinaryProfile($ProfileName);

            case 'enum':
                return $this->createEnumProfile($expose, $ProfileName);

            case 'numeric':
                $result = $this->createNumericProfile($expose);
                if (!isset($result['mainProfile'])) {
                    $this->SendDebug(__FUNCTION__, 'Error: No mainProfile returned from createNumericProfile', 0);
                    // throw new Exception('No mainProfile returned from createNumericProfile');
                }
                $this->SendDebug(__FUNCTION__, 'Created numeric profile: ' . $result['mainProfile'], 0);
                return $result['mainProfile'];

            case 'climate':
                // Für climate-Typen die Features einzeln verarbeiten
                if (isset($expose['features'])) {
                    foreach ($expose['features'] as $feature) {
                        $featureProfileName = 'Z2M.' . strtolower($feature['property']);
                        $this->handleProfileType($feature['type'], $feature, $featureProfileName);
                    }
                }
                return $ProfileName;

            case 'color_temp':
                $this->registerSpecialVariable($expose);
                return $ProfileName;

            default:
                $this->SendDebug(__FUNCTION__, 'Unsupported profile type: ' . $type, 0);
                return $ProfileName;
        }
    }

// Profiltypen

    /**
     * Erstellt ein binäres Profil für Variablen mit zwei Zuständen.
     *
     * Diese Methode erstellt ein Profil für boolesche Werte mit folgenden Eigenschaften:
     * - Zwei Zustände (An/Aus bzw. true/false)
     * - Farbkodierung (Grün für An, Rot für Aus)
     * - Power-Icon für die Visualisierung
     * - Übersetzbare Beschriftungen
     *
     * @param string $ProfileName Der eindeutige Name für das zu erstellende Profil (z.B. 'Z2M.Switch')
     *
     * @return string Der Name des erstellten Profils, identisch mit dem Eingabeparameter
     *
     * @example
     * $profile = $this->createBinaryProfile('Z2M.Switch');
     * // Erstellt ein Profil mit den Werten:
     * // false -> "Aus" (rot)
     * // true  -> "An"  (grün)
     */
    private function createBinaryProfile(string $ProfileName): string
    {
        // Registriere das Boolean-Profil mit ON/OFF Werten
        $this->RegisterProfileBooleanEx(
            $ProfileName,
            'Power',  // Icon
            '',       // Prefix
            '',       // Suffix
            [
                [false, $this->Translate('Off'), '', 0xFF0000],  // Rot für Aus
                [true, $this->Translate('On'), '', 0x00FF00]     // Grün für An
            ]
        );

        $this->SendDebug(__FUNCTION__, "Binary-Profil erstellt: $ProfileName", 0);
        return $ProfileName;
    }

    /**
     * Erstellt ein Profil für Enum-Werte basierend auf den Expose-Daten.
     *
     * @param array $expose Die Expose-Daten mit folgenden Schlüsseln:
     *                     - 'values': Array mit möglichen Enum-Werten (erforderlich)
     *                     Beispiel: ['off', 'on', 'toggle']
     * @param string $ProfileName Basis-Name des zu erstellenden Profils
     *                           Der tatsächliche Profilname wird um einen CRC32-Hash erweitert
     *
     * @return string Name des erstellten Profils (Format: BasisName.HashWert)
     *
     * @example
     * $expose = [
     *     'values' => ['auto', 'manual', 'boost']
     * ];
     * $profile = $this->createEnumProfile($expose, 'Z2M.Mode');
     * // Ergebnis: Z2M.Mode.a1b2c3d4
     *
     * @note Die Werte werden automatisch:
     *       - Sortiert für konsistente Hash-Generierung
     *       - In lesbare Form konvertiert (z.B. manual -> Manual)
     *       - Übersetzt falls in locale.json vorhanden
     *       - In translations.json hinzugefügt falls nicht vorhanden
     */
    private function createEnumProfile(array $expose, string $ProfileName): string
    {
        if (!array_key_exists('values', $expose)) {
            $this->SendDebug(__FUNCTION__, "Keine Werte für Enum-Profil gefunden", 0);
            return $ProfileName;
        }

        // Sortiere Werte für konsistente CRC32-Berechnung
        sort($expose['values']);

        // Erstelle eindeutigen Profilnamen basierend auf den Werten
        $tmpProfileName = implode('', $expose['values']);
        $ProfileName .= '.' . dechex(crc32($tmpProfileName));

        // Erstelle Profilwerte
        $profileValues = [];
        foreach ($expose['values'] as $value) {
            $readableValue = ucwords(str_replace('_', ' ', (string) $value));
            $translatedValue = $this->Translate($readableValue);

            // Prüfe, ob der Wert in der locale.json vorhanden ist
            if (!$this->isValueInLocaleJson($readableValue)) {
                // Füge den Wert zur translations.json hinzu
                $this->addValueToTranslationsJson($readableValue);
            }

            $profileValues[] = [(string) $value, $translatedValue, '', 0x00FF00];
        }

        // Registriere das Profil
        $this->RegisterProfileStringEx(
            $ProfileName,
            'Menu',
            '',
            '',
            $profileValues
        );

        $this->SendDebug(__FUNCTION__, "Enum-Profil erstellt: $ProfileName mit Werten: " . json_encode($profileValues), 0);
        return $ProfileName;
    }

    /**
     * Erstellt ein numerisches Variablenprofil (ganzzahlig oder Gleitkomma) basierend auf den Expose-Daten.
     *
     * @param array $expose Die Expose-Daten mit folgenden Schlüsseln:
     *                     - 'type': Typ des Exposes (string)
     *                     - 'property': Name der Eigenschaft (string)
     *                     - 'unit': Optional - Einheit des Wertes (string)
     *                     - 'value_step': Optional - Schrittweite (float|int)
     *                     - 'value_min': Optional - Minimaler Wert (float|int)
     *                     - 'value_max': Optional - Maximaler Wert (float|int)
     *                     - 'presets': Optional - Array mit vordefinierten Werten
     *
     * @return array Assoziatives Array mit:
     *               - 'mainProfile': string - Name des Hauptprofils
     *               - 'presetProfile': string|null - Name des Preset-Profils, falls vorhanden
     *
     * @throws \Exception Wenn ein Standard-Profil ungültig ist
     *
     * @example
     * $expose = [
     *     'type' => 'numeric',
     *     'property' => 'temperature',
     *     'unit' => '°C',
     *     'value_min' => 0,
     *     'value_max' => 40,
     *     'value_step' => 0.5
     * ];
     * $result = $this->createNumericProfile($expose);
     */
    private function createNumericProfile($expose) {
        // Frühe Typ-Bestimmung
        $type = $expose['type'] ?? '';
        $feature = $expose['property'] ?? '';
        $unit = isset($expose['unit']) && is_string($expose['unit']) ? $expose['unit'] : '';
        $value_step = isset($expose['value_step']) ? floatval($expose['value_step']) : 1.0;

        // Bestimme Variablentyp
        $variableType = $this->getVariableTypeFromProfile($type, $feature, $unit, $value_step);
        $this->SendDebug(__FUNCTION__, 'Initial Variable Type: ' . $variableType, 0);

        // Standardprofil-Prüfung
        $standardProfile = $this->getStandardProfile($type, $feature);
        if ($standardProfile !== null) {
            if (!is_string($standardProfile)) {
                throw new \Exception('Standard Profile muss ein String sein.');
            }

            if (strpos($standardProfile, '~') === 0 && IPS_VariableProfileExists($standardProfile)) {
                return ['mainProfile' => $standardProfile, 'presetProfile' => null];
            }
            return ['mainProfile' => $standardProfile, 'presetProfile' => null];
        }

        // Eigenes Profil erstellen
        $fullRangeProfileName = $this->getFullRangeProfileName($expose);
        $min = $expose['value_min'] ?? 0;
        $max = $expose['value_max'] ?? 0;
        $step = $expose['value_step'] ?? 1.0;
        $unitWithSpace = $unit !== '' ? ' ' . mb_convert_encoding(mb_convert_encoding($unit, 'ISO-8859-1', 'UTF-8'), 'ISO-8859-1', 'UTF-8') : '';

        // Profil entsprechend Variablentyp erstellen
        if (!IPS_VariableProfileExists($fullRangeProfileName)) {
            if ($variableType === 'float') {
                $this->RegisterProfileFloat($fullRangeProfileName, '', '', $unitWithSpace, floatval($min), floatval($max), floatval($step), 2);
                $this->SendDebug(__FUNCTION__, 'Created Float Profile: ' . $fullRangeProfileName, 0);
            } else {
                $this->RegisterProfileInteger($fullRangeProfileName, '', '', $unitWithSpace, intval($min), intval($max), intval($step));
                $this->SendDebug(__FUNCTION__, 'Created Integer Profile: ' . $fullRangeProfileName, 0);
            }
        }

        // Preset-Handling
        $presetProfileName = null;
        if (isset($expose['presets']) && !empty($expose['presets'])) {
            $formattedLabel = $this->convertLabelToName($feature);
            $presetProfileName = $this->createPresetProfile($expose['presets'], $formattedLabel, $variableType, $expose);
        }

        return ['mainProfile' => $fullRangeProfileName, 'presetProfile' => $presetProfileName];
    }

    /**
     * Erstellt ein benutzerdefiniertes Stringprofil für Variablen.
     *
     * Diese Methode erstellt ein Profil für String-Variablen mit benutzerdefinierten Eigenschaften:
     * - Anpassbare Werte und Bezeichnungen
     * - Optionale Farbzuordnung
     * - Optionale Icons
     *
     * @param string $ProfileName Der eindeutige Name für das zu erstellende Profil (z.B. 'Z2M.CustomString')
     * @param array $associations Array mit Zuordnungen von Werten zu Bezeichnungen
     *                           Format: [['value' => string, 'label' => string], ...]
     *
     * @return string Der Name des erstellten Profils
     *
     * @throws Exception Wenn das Profil bereits existiert oder ungültige Parameter übergeben werden
     *
     * @example
     * $associations = [
     *     ['value' => 'auto', 'label' => 'Automatisch'],
     *     ['value' => 'manual', 'label' => 'Manuell']
     * ];
     * $profile = $this->createCustomStringProfile('Z2M.Mode', $associations);
     */
    private function createCustomStringProfile(string $ProfileName, $valueOn, $valueOff): string
    {
        // Erstelle Profilwerte
        $profileValues = [
            [false, $valueOff, '', 0xFF0000],  // Rot für Aus
            [true, $valueOn, '', 0x00FF00]     // Grün für An
        ];

        // Registriere das Profil
        $this->RegisterProfileStringEx(
            $ProfileName,
            'Power',  // Icon
            '',       // Prefix
            '',       // Suffix
            $profileValues
        );

        $this->SendDebug(__FUNCTION__, "Custom String-Profil erstellt: $ProfileName mit Werten: " . json_encode($profileValues), 0);
        return $ProfileName;
    }

    /**
     * Registriert ein Variablenprofil für Presets basierend auf den übergebenen Preset-Daten.
     *
     * Diese Funktion generiert ein Profil für eine Preset-Variable, das verschiedene vordefinierte Werte enthält.
     * Der Profilname wird dynamisch basierend auf dem übergebenen Label und den Min- und Max-Werten erstellt.
     * Falls ein Profil mit diesem Namen bereits existiert, wird es gelöscht und neu erstellt.
     *
     * Jedes Preset im übergebenen Array wird mit seinem Namen und Wert dem Profil hinzugefügt. Der Name des Presets
     * wird dabei ins Lesbare umgewandelt (z.B. von snake_case in normaler Text), und die zugehörigen Werte werden
     * als Assoziationen im Profil gespeichert. Die Presets erhalten außerdem eine standardmäßige weiße Farbe
     * für die Anzeige.
     *
     * @param array $presets Ein Array von Presets, die jeweils einen Namen und einen zugehörigen Wert enthalten.
     *                       Beispielstruktur eines Presets:
     *                       [
     *                           'name'  => 'coolest',    // Name des Presets
     *                           'value' => 153           // Wert des Presets
     *                       ]
     * @param string $label Der Name, der dem Profil zugeordnet wird. Leerzeichen im Label werden durch Unterstriche ersetzt.
     * @param string $variableType Der Variablentyp (z.B. 'float', 'int').
     * @param array $feature Die Expose-Daten, die die Eigenschaften des Features enthalten, einschließlich Min- und Max-Werten.
     *
     * @return string Der Name des erstellten Profils.
     */
    private function createPresetProfile(array $presets, string $label, string $variableType, array $feature): string
    {
        // Profilname ohne Leerzeichen erstellen und Min- und Max-Werte hinzufügen
        $profileName = 'Z2M.' . str_replace(' ', '_', $label);
        $valueMin = $feature['value_min'] ?? null;
        $valueMax = $feature['value_max'] ?? null;

        if ($valueMin !== null && $valueMax !== null) {
            $profileName .= '_' . $valueMin . '_' . $valueMax;
        }

        $profileName .= '_Presets';

        try {
            // Wenn das Profil bereits existiert, zuerst entfernen
            if (IPS_VariableProfileExists($profileName)) {
                IPS_DeleteVariableProfile($profileName);
            }

            if (!IPS_VariableProfileExists($profileName)) {
                // Neues Profil anlegen
                if ($variableType === 'float') {
                    if (!$this->RegisterProfileFloatEx($profileName, '', '', '', [])) {
                        $this->LogMessage(sprintf('%s: Could not create float profile %s', __FUNCTION__, $profileName), KL_DEBUG);
                    }
                } else {
                    if (!$this->RegisterProfileIntegerEx($profileName, '', '', '', [])) {
                        $this->LogMessage(sprintf('%s: Could not create integer profile %s', __FUNCTION__, $profileName), KL_DEBUG);
                    }
                }
            }

            // Füge die Presets zum Profil hinzu
            foreach ($presets as $preset) {
                // Preset-Wert an den Variablentyp anpassen
                $presetValue = ($variableType === 'float') ? floatval($preset['value']) : intval($preset['value']);
                $presetName = $this->Translate(ucwords(str_replace('_', ' ', $preset['name'])));

                $this->SendDebug(__FUNCTION__, sprintf('Adding preset: %s with value %s', $presetName, $presetValue), 0);
                IPS_SetVariableProfileAssociation($profileName, $presetValue, $presetName, '', -1);
            }
        } catch (Exception $e) {
            $this->LogMessage(sprintf('%s: Error handling profile %s: %s', __FUNCTION__, $profileName, $e->getMessage()), KL_DEBUG);
        }

        return $profileName;
    }

// JSON & Dateimanagement

    /**
     * Prüft und erstellt eine JSON-Datei für die Zigbee-Geräteinformationen.
     *
     * Diese Methode führt folgende Schritte aus:
     * 1. Prüft ob ein MQTT-Topic gesetzt ist
     * 2. Überprüft das Vorhandensein der JSON-Datei im Zigbee2MQTTExposes Verzeichnis
     * 3. Wartet auf aktive MQTT-Verbindung
     * 4. Ruft UpdateDeviceInfo() auf um Geräteinformationen zu aktualisieren
     * 5. Prüft die erfolgreiche Erstellung der JSON-Datei
     *
     * Die JSON-Datei wird im Format "InstanzID.json" im Verzeichnis "Zigbee2MQTTExposes" gespeichert
     * und enthält die Expose-Informationen des Zigbee-Geräts.
     *
     * @throws Exception Wenn das Verzeichnis nicht erstellt werden kann
     * @see UpdateDeviceInfo()
     * @return void
     */
    private function checkAndCreateJsonFile(): void
    {
        $instanceID = $this->InstanceID;
        $mqttTopic = $this->ReadPropertyString('MQTTTopic');

        // Erst prüfen ob MQTTTopic gesetzt ist
        if (empty($mqttTopic)) {
            $this->SendDebug(__FUNCTION__, "MQTTTopic nicht gesetzt, überspringe JSON Prüfung", 0);
            return;
        }

        $kernelDir = rtrim(IPS_GetKernelDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $verzeichnisName = 'Zigbee2MQTTExposes';
        $vollerPfad = $kernelDir . $verzeichnisName . DIRECTORY_SEPARATOR;
        $jsonFile = $vollerPfad . $instanceID . '.json';

        // Prüfe ob JSON existiert
        if (!file_exists($jsonFile)) {
            $this->SendDebug(__FUNCTION__, "JSON-Datei nicht gefunden für Instance: " . $instanceID, 0);

            // Nur fortfahren wenn Parent aktiv
            if (!$this->HasActiveParent()) {
                $this->SendDebug(__FUNCTION__, "Parent nicht aktiv, überspringe UpdateDeviceInfo", 0);
                return;
            }

            // Prüfe erneut Parent Status nach Wartezeit
            if ($this->HasActiveParent() && (IPS_GetKernelRunlevel() == KR_READY)) {
                $this->SendDebug(__FUNCTION__, "Starte UpdateDeviceInfo für Topic: " . $mqttTopic, 0);
                if (!$this->UpdateDeviceInfo()) {
                    $this->SendDebug(__FUNCTION__, "UpdateDeviceInfo fehlgeschlagen - erster Versuch", 0);
                    // Zweiter Versuch nach 3 Sekunden
                    IPS_Sleep(20);
                    if (!$this->UpdateDeviceInfo()) {
                        $this->SendDebug(__FUNCTION__, "UpdateDeviceInfo fehlgeschlagen - zweiter Versuch", 0);
                        return;
                    }
                }

                // Prüfe ob JSON erstellt wurde
                if (!file_exists($jsonFile)) {
                    $this->SendDebug(__FUNCTION__, "JSON-Datei konnte nicht erstellt werden", 0);
                }
            }
        }
    }

    /**
     * Lädt und verarbeitet die bekannten Variablen aus den gespeicherten JSON-Expose-Dateien.
     *
     * Diese Methode durchsucht das Zigbee2MQTTExposes-Verzeichnis nach einer JSON-Datei, die der aktuellen Instanz-ID entspricht.
     * Sie extrahiert alle Features aus den Exposes und erstellt daraus ein Array von bekannten Variablen.
     *
     * Der Prozess beinhaltet:
     * - Suche nach der JSON-Datei im Symcon-Kernel-Verzeichnis
     * - Laden und Dekodieren der JSON-Daten
     * - Extraktion der Features aus den Exposes
     * - Filterung nach Features mit 'property'-Attribut
     * - Normalisierung der Feature-Namen (Kleinbuchstaben, getrimmt)
     *
     * Dateistruktur:
     * {
     *     "exposes": [
     *         {
     *             "features": [...],
     *             "property": "example_property"
     *         }
     *     ]
     * }
     *
     * @internal Diese Methode wird intern vom Modul verwendet
     *
     * @throws \Exception Indirekt durch file_get_contents() wenn die Datei nicht gelesen werden kann
     *
     * @return array Ein assoziatives Array mit bekannten Variablen, wobei der Key der normalisierte Property-Name ist
     *               und der Value die komplette Feature-Definition enthält.
     *               Format: ['property_name' => ['property' => 'name', ...]]
     *               Leeres Array wenn keine Variablen gefunden wurden.
     *
     * @see registerVariable() Verwendet die zurückgegebenen Variablen zur Registrierung
     * @see DecodeData() Nutzt die Variablen zur Datendekodierung
     */
    private function getKnownVariables(): array
    {
        $instanceID = $this->InstanceID;

        $kernelDir = rtrim(IPS_GetKernelDir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $verzeichnisName = 'Zigbee2MQTTExposes';
        $vollerPfad = $kernelDir . $verzeichnisName . DIRECTORY_SEPARATOR;
        $dateiPfadPattern = $vollerPfad . $instanceID . '.json';

        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__, "Suche nach Dateien mit Muster: " . $dateiPfadPattern, 0);
        $files = glob($dateiPfadPattern);

        if (empty($files)) {
            $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__, "Keine JSON-Dateien gefunden, die dem Muster entsprechen: " . $dateiPfadPattern, 0);
            return [];
        }

        $knownVariables = [];

        foreach ($files as $dateiPfad) {
            $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__, "Verarbeite Datei: " . $dateiPfad, 0);
            if (!file_exists($dateiPfad)) {
                $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__, "JSON-Datei nicht gefunden: " . $dateiPfad, 0);
                continue;
            }

            $jsonData = file_get_contents($dateiPfad);
            $data = json_decode($jsonData, true);

            if (json_last_error() !== JSON_ERROR_NONE || !isset($data['exposes'])) {
                $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__, "Fehler beim Dekodieren der JSON-Datei oder fehlende 'exposes' in Datei: $dateiPfad. Fehler: " . json_last_error_msg(), 0);
                continue;
            }

            $exposes = $data['exposes'];

            $features = array_map(function ($expose) {
                return isset($expose['features']) ? $expose['features'] : [$expose];
            }, $exposes);

            $features = array_merge(...$features);

            $filteredFeatures = array_filter($features, function ($feature) {
                return isset($feature['property']);
            });

            foreach ($filteredFeatures as $feature) {
                $variableName = trim(strtolower($feature['property']));
                $knownVariables[$variableName] = $feature;
            }
        }

        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__, 'Known Variables Array:', 0);
        foreach ($knownVariables as $varName => $varProps) {
            // $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__, "'" . $varName . "'", 0);
        }

        return $knownVariables;
    }

    /**
     * Prüft, ob ein Wert in der locale.json vorhanden ist.
     *
     * @param string $value Der zu prüfende Wert.
     * @return bool Gibt true zurück, wenn der Wert in der locale.json vorhanden ist, andernfalls false.
     */
    private function isValueInLocaleJson(string $value): bool
    {
        $globalJsonFilePath = str_replace(
            '{base_dir}',
            (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? 'C:\\ProgramData\\Symcon\\modules\\IPS-Zigbee2MQTT-Burki\\Device' : '/var/lib/symcon/modules/IPS-Zigbee2MQTT-Burki/Device',
            '{base_dir}/locale.json'
        );

        if (file_exists($globalJsonFilePath)) {
            $globalJsonData = file_get_contents($globalJsonFilePath);
            $globalTranslations = json_decode($globalJsonData, true);

            if (isset($globalTranslations['translations']['de'])) {
                foreach ($globalTranslations['translations']['de'] as $key => $translation) {
                    if ($key === $value) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Fügt einen Wert zur translations.json hinzu, wenn er noch nicht vorhanden ist.
     * Gibt eine Liste an Begriffen, die noch in der locale.json ergänzt werden müssen.
     *
     * @param string $value Der hinzuzufügende Wert.
     * @return void
     */
    private function addValueToTranslationsJson(string $value): void
    {
        $jsonFilePath = __DIR__ . '/translations.json';

        // Lade bestehende Übersetzungen
        $translations = [];
        if (file_exists($jsonFilePath)) {
            $jsonData = file_get_contents($jsonFilePath);
            $translations = json_decode($jsonData, true);
        }

        // Füge den neuen Begriff hinzu, wenn er noch nicht existiert
        if (!in_array($value, $translations)) {
            $translations[] = $value;
            file_put_contents($jsonFilePath, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

// Feature & Expose Handling

    /**
     * Mappt die übergebenen Exposes auf Variablen und registriert diese.
     * Diese Funktion verarbeitet die übergebenen Exposes (z.B. Sensoreigenschaften) und registriert sie als Variablen.
     * Wenn ein Expose mehrere Features enthält, werden diese ebenfalls einzeln registriert.
     *
     * @param array $exposes Ein Array von Exposes, das die Geräteeigenschaften oder Sensoren beschreibt.
     *
     * @return void
     */
    protected function mapExposesToVariables(array $exposes)
    {
        $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: All Exposes', json_encode($exposes), 0);

        // Durchlaufe alle Exposes
        foreach ($exposes as $expose) {
            // Prüfen, ob es sich um eine Gruppe handelt
            if (isset($expose['type']) && in_array($expose['type'], ['light', 'switch', 'lock', 'cover', 'climate', 'fan', 'text'])) {
                $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Found group: ', $expose['type'], 0);

                // Features in der Gruppe verarbeiten
                if (isset($expose['features']) && is_array($expose['features'])) {
                    foreach ($expose['features'] as $feature) {
                        $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Processing feature in group: ', json_encode($feature), 0);
                        // Setze den Gruppentyp als zusätzlichen Wert
                        $feature['group_type'] = $expose['type'];
                        // Variablen für die einzelnen Features registrieren
                        $this->registerVariable($feature);

                        // Wenn es sich um brightness handelt, speichere die Min/Max Werte
                        if ($feature['property'] === 'brightness') {
                            $brightnessConfig = [
                                'min' => $feature['value_min'] ?? 0,
                                'max' => $feature['value_max'] ?? 255
                            ];
                            $this->SetBuffer('brightnessConfig', json_encode($brightnessConfig));
                            $this->SendDebug(__FUNCTION__, 'Brightness Config: ' . json_encode($brightnessConfig), 0);
                        }
                    }
                }
            } else {
                $this->registerVariable($expose);
                if (isset($expose['presets'])) {
                    $formattedLabel = $this->convertLabelToName($expose['property']);
                    $variableType = $this->getVariableTypeFromProfile($expose['type'], $expose['property'], $expose['unit'] ?? '', $expose['step'] ?? null, null);
                    $this->registerPresetVariables($expose['presets'], $formattedLabel, $variableType, $expose);
                }
            }
        }
    }

    /**
     * Registriert eine Variable basierend auf den Feature-Informationen
     * @param array|string $feature Feature-Information oder Feature-ID
     * @param string|null $exposeType Optionaler Expose-Typ
     * @return mixed
     */
    private function registerVariable($feature, $exposeType = null): mixed
    {
        // Während Migration keine Variablen erstellen
        if($this->GetBuffer(self::BUFFER_KEYS['PROCESSING_MIGRATION']) === 'true') {
            return false;
        }

        $featureId = is_array($feature) ? $feature['property'] : $feature;
        $this->SendDebug(__FUNCTION__ . "Registriere Variable für Property: ", $featureId, 0);

        // Übergebe das komplette Feature-Array für Access-Check
        $stateConfig = $this->getStateConfiguration($featureId, is_array($feature) ? $feature : null);
        $formattedLabel = $this->convertLabelToName($featureId);
        if ($stateConfig !== null) {
            $variableId = $this->RegisterVariableBoolean(
                $stateConfig['ident'],
                $this->Translate($formattedLabel),
                $stateConfig['profile']
            );

            if (isset($stateConfig['enableAction']) && $stateConfig['enableAction']) {
                $this->EnableAction($stateConfig['ident']);
                $this->SendDebug(__FUNCTION__, "Enabled action for $featureId (writable state)", 0);
            }

            return $variableId;
        }

        // Weitere Verarbeitung für Standard-Features
        if (is_array($feature)) {
            $name = $feature['name'] ?? $featureId;
            $unit = $feature['unit'] ?? ''; // Falls 'unit' nicht gesetzt ist, verwenden wir einen leeren String
        }

        // Überprüfung auf spezielle Fälle
        if (isset(self::$specialVariables[$feature['property']])) {
            $this->registerSpecialVariable($feature);
            return null;
        }

        // Setze den Typ auf den übergebenen Expose-Typ, falls vorhanden
        if ($exposeType !== null) {
            $feature['type'] = $exposeType;
        }

        // Berücksichtige den Gruppentyp, falls vorhanden, ohne den ursprünglichen Typ zu überschreiben
        $groupType = $feature['group_type'] ?? null;

        $this->SendDebug(__FUNCTION__ . ' :: Registering Feature', json_encode($feature), 0);

        $type = $feature['type'];
        $property = $feature['property'] ?? '';
        $unit = $feature['unit'] ?? '';
        $ident = $property;
        $label = ucfirst(str_replace('_', ' ', $property));
        $step = isset($feature['step']) ? floatval($feature['step']) : 1.0;

        // Überprüfen, ob die Variable bereits existiert
        $objectID = @$this->GetIDForIdent($ident);
        if ($objectID) {
            $this->SendDebug(__FUNCTION__ . ' :: Variable already exists: ', $ident, 0);
            return null;
        }

        // Bestimmen des Variablentyps basierend auf Typ, Feature und Einheit
        $variableType = $this->getVariableTypeFromProfile($type, $property, $unit, $step, $groupType);

        // Überprüfen, ob ein Standardprofil verwendet werden soll
        $profileName = $this->getStandardProfile($type, $property, $groupType);

        // Profil vor der Variablenerstellung erstellen, falls kein Standardprofil verwendet wird
        if ($profileName === null) {
            $profileName = $this->registerVariableProfile($feature);
        }

        // Registrierung der Variable basierend auf dem Variablentyp
        $formattedLabel = $this->convertLabelToName($label);
        $isSwitchable = isset($feature['access']) && ($feature['access'] & 0b010) != 0;

        switch ($variableType) {
            case 'bool':
                $this->SendDebug(__FUNCTION__, 'Registering Boolean Variable: ' . $ident, 0);
                $this->RegisterVariableBoolean($ident, $this->Translate($formattedLabel));
                break;
            case 'int':
                $this->SendDebug(__FUNCTION__, 'Registering Integer Variable: ' . $ident, 0);
                $this->RegisterVariableInteger($ident, $this->Translate($formattedLabel));
                break;
            case 'float':
                $this->SendDebug(__FUNCTION__, 'Registering Float Variable: ' . $ident, 0);
                $this->RegisterVariableFloat($ident, $this->Translate($formattedLabel));
                break;
            case 'string':
            case 'text':
                $this->SendDebug(__FUNCTION__, 'Registering String Variable: ' . $ident, 0);
                $this->RegisterVariableString($ident, $this->Translate($formattedLabel));
                break;
            // Zusätzliche Registrierung für 'composite' Farb-Variablen
            case 'composite':
                $this->SendDebug(__FUNCTION__, 'Registering Composite Variable: ' . $ident, 0);
                $this->registerColorVariable($ident, $feature);
                return null;
            default:
                $this->SendDebug(__FUNCTION__, 'Unsupported variable type: ' . $variableType, 0);
                return null;
        }

        // Profil nach der Variablenerstellung zuordnen
        if (!empty($profileName)) {
            if (IPS_VariableProfileExists($profileName)) {
                $variableID = $this->GetIDForIdent($ident);
                $variable = IPS_GetVariable($variableID);

                // Sicherstellen, dass der Profiltyp mit dem Variablentyp übereinstimmt
                $profile = IPS_GetVariableProfile($profileName);
                if ($profile['ProfileType'] == $variable['VariableType']) {
                    IPS_SetVariableCustomProfile($variableID, $profileName);
                    $this->SendDebug(__FUNCTION__, 'Assigned profile ' . $profileName . ' to variable with ident ' . $ident, 0);
                } else {
                    $this->SendDebug(__FUNCTION__, 'Profiltyp und Variablentyp stimmen nicht überein für Ident: ' . $ident, 0);
                }
            } else {
                $this->SendDebug(__FUNCTION__, 'Profile ' . $profileName . ' does not exist for ident: ' . $ident, 0);
            }
        }

        if ($isSwitchable) {
            $this->EnableAction($ident);
            $this->SendDebug(__FUNCTION__, 'Set EnableAction for ident: ' . $ident . ' to: true', 0);
        }

        // Zusätzliche Registrierung der color_temp_kelvin Variable, wenn color_temp registriert wird
        if ($ident === 'color_temp') {
            $kelvinIdent = $ident . '_kelvin';
            $this->SendDebug(__FUNCTION__, 'TWColor Profile exists: ' . (IPS_VariableProfileExists('~TWColor') ? 'yes' : 'no'), 0);
            $this->SendDebug(__FUNCTION__, 'Registering Kelvin variable with ident: ' . $kelvinIdent, 0);

            $variableId = $this->RegisterVariableInteger($kelvinIdent, $this->Translate('Color Temperature Kelvin'), '~TWColor');
            $this->SendDebug(__FUNCTION__, 'Registered variable ID: ' . $variableId, 0);
            $profile = IPS_GetVariable($variableId)['VariableProfile'];
            $this->SendDebug(__FUNCTION__, 'Assigned profile: ' . $profile, 0);
            $this->EnableAction($kelvinIdent);

        }

        // Preset-Verarbeitung nach der normalen Variablenregistrierung
        if (isset($feature['presets']) && !empty($feature['presets'])) {
            $formattedLabel = $this->convertLabelToName($feature['property']);
            $variableType = $this->getVariableTypeFromProfile($type, $property, $unit, $step, $groupType);
            $this->registerPresetVariables($feature['presets'], $formattedLabel, $variableType, $feature);
            $this->SendDebug(__FUNCTION__, 'Registered presets for: ' . $formattedLabel, 0);
        }
        return null;
    }

    /**
     * Registriert Farbvariablen für verschiedene Farbmodelle.
     *
     * Diese Methode erstellt und registriert spezielle Variablen für die Farbsteuerung
     * von Zigbee-Geräten. Unterstützt werden die Farbmodelle:
     * - XY-Farbraum (color_xy)
     * - HSV-Farbraum (color_hs)
     * - RGB-Farbraum (color_rgb)
     *
     * @param string $ident Der Identifikator für die Variable
     * @param array $feature Array mit Eigenschaften des Features:
     *                       - 'name': Name des Farbmodells ('color_xy', 'color_hs', 'color_rgb')
     */
    private function registerColorVariable($ident, $feature)
    {
        // Während Migration keine Variablen erstellen
        if($this->GetBuffer(self::BUFFER_KEYS['PROCESSING_MIGRATION']) === 'true') {
            return false;
        }

        switch ($feature['name']) {
            case 'color_xy':
                $this->RegisterVariableInteger('color', $this->Translate($this->convertLabelToName('color')), 'HexColor');
                $this->EnableAction('color');
                $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Creating composite color_xy', 'color', 0);
                break;
            case 'color_hs':
                $this->RegisterVariableInteger('color_hs', $this->Translate($this->convertLabelToName('color_hs')), 'HexColor');
                $this->EnableAction('color_hs');
                $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Creating composite color_hs', 'color_hs', 0);
                break;
            case 'color_rgb':
                $this->RegisterVariableInteger('color_rgb', $this->Translate($this->convertLabelToName('color_rgb')), 'HexColor');
                $this->EnableAction('color_rgb');
                $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Creating composite color_rgb', 'color_rgb', 0);
                break;
            default:
                $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Unhandled composite type', $feature['name'], 0);
                break;
        }
    }

    /**
     * Registriert Variablen und Profile für Presets eines Features.
     *
     * Diese Funktion erstellt für ein Feature eine zusätzliche Preset-Variable mit entsprechendem Profil.
     * Sie wird verwendet, um vordefinierte Werte (Presets) für bestimmte Eigenschaften eines Geräts
     * zugänglich zu machen.
     *
     * @param array $presets Array mit Preset-Definitionen. Jedes Preset enthält:
     *                       - 'name': Name des Presets (string)
     *                       - 'value': Wert des Presets (mixed)
     * @param string $label Bezeichnung für die Variable
     * @param string $variableType Typ der Variable ('float' oder 'int')
     * @param array $feature Feature-Definition mit zusätzlichen Eigenschaften wie:
     *                       - 'property': Name der Eigenschaft
     *                       - 'name': Anzeigename
     *                       - 'value_min': Minimaler Wert (optional)
     *                       - 'value_max': Maximaler Wert (optional)
     * @return void
     *
     * @example
     * $presets = [
     *     ['name' => 'low', 'value' => 20],
     *     ['name' => 'medium', 'value' => 50],
     *     ['name' => 'high', 'value' => 100]
     * ];
     * $this->registerPresetVariables($presets, 'Brightness', 'int', ['property' => 'brightness', 'name' => 'Brightness']);
     */
    private function registerPresetVariables(array $presets, string $label, string $variableType, array $feature): void
    {
        // Während Migration keine Variablen erstellen
        if($this->GetBuffer(self::BUFFER_KEYS['PROCESSING_MIGRATION']) === 'true') {
            return;
        }

        $this->SendDebug(__FUNCTION__, 'Registering preset variables for: ' . $label, 0);
        $profileName = $this->createPresetProfile($presets, $label, $variableType, $feature);

        // Variable registrieren
        $ident = ($feature['property']) . '_presets';
        $this->SendDebug(__FUNCTION__, 'Preset ident: ' . $ident, 0);
        $label = $this->Translate($feature['name']) . ' Presets';
        $formattedLabel = $this->convertLabelToName($label);

        // Überprüfen, ob die Variable bereits existiert
        $variableID = @$this->GetIDForIdent($ident);
        if ($variableID === false) {
            // Variable erstellen
            if ($variableType === 'float') {
                $this->RegisterVariableFloat($ident, $this->Translate($formattedLabel), $profileName);
                $this->EnableAction($ident);
            } else {
                $this->RegisterVariableInteger($ident, $this->Translate($formattedLabel), $profileName);
                $this->EnableAction($ident);
            }
        } else {
            $this->SendDebug(__FUNCTION__, 'Variable already exists: ' . $ident, 0);
        }
    }

    /**
     * Registriert spezielle Variablen.
     *
     * @param array $feature Feature-Eigenschaften
     */
    private function registerSpecialVariable($feature)
    {
        // Während Migration keine Variablen erstellen
        if($this->GetBuffer(self::BUFFER_KEYS['PROCESSING_MIGRATION']) === 'true') {
            return false;
        }

        $property = $feature['property'];
        $this->SendDebug(__FUNCTION__, sprintf('Checking special case for %s: %s', $property, json_encode($feature)), 0);

        if (!isset(self::$specialVariables[$property])) {
            return false;
        }

        $varDef = self::$specialVariables[$property];
        $ident = $property;
        $formattedLabel = $this->convertLabelToName($property);

        // Wert anpassen wenn nötig
        if (isset($feature['value'])) {
            $value = $this->adjustSpecialValue($ident, $feature['value']);
        }

        switch($varDef['type']) {
            case VARIABLETYPE_FLOAT:
                $this->RegisterVariableFloat($ident, $this->Translate($formattedLabel), $varDef['profile'] ?? '');
                if (isset($value)) {
                    $this->SetValue($ident, $value);
                }
                break;
            case VARIABLETYPE_INTEGER:
                $this->RegisterVariableInteger($ident, $this->Translate($formattedLabel), $varDef['profile'] ?? '');
                if (isset($value)) {
                    $this->SetValue($ident, $value);
                }
                break;
            case VARIABLETYPE_STRING:
                $this->RegisterVariableString($ident, $this->Translate($formattedLabel));
                if (isset($value)) {
                    $this->SetValue($ident, $value);
                }
                break;
            case VARIABLETYPE_BOOLEAN:
                $this->RegisterVariableBoolean($ident, $this->Translate($formattedLabel), $varDef['profile'] ?? '');
                if (isset($value)) {
                    $this->SetValue($ident, $value);
                }
                break;
        }

        if ($varDef['enableAction'] ?? false) {
            $this->EnableAction($ident);
        }
        return true;
    }

    /**
     * Prüft und liefert die Konfiguration für State-basierte Features.
     *
     * Diese Methode analysiert ein Feature und bestimmt, ob es sich um ein State-Feature handelt.
     * Sie prüft zwei Szenarien:
     * 1. Standard State-Pattern (z.B. "state", "state_left")
     * 2. Vordefinierte States aus stateDefinitions
     *
     * Die zurückgegebene Konfiguration enthält:
     * - type: Typ des States (z.B. 'switch')
     * - dataType: IPS Variablentyp (z.B. VARIABLETYPE_BOOLEAN)
     * - values: Mögliche Zustände (z.B. ['ON', 'OFF'])
     * - profile: Zu verwendenes IPS-Profil
     * - enableAction: Ob Aktionen erlaubt sind (basierend auf access)
     * - ident: Normalisierter Identifikator
     *
     * @param string $featureId Feature-Identifikator (z.B. 'state', 'state_left')
     * @param array|null $feature Optionales Feature-Array mit weiteren Eigenschaften wie:
     *                           - access: Zugriffsrechte für Schreiboperationen
     *
     * @return array|null Array mit State-Konfiguration oder null wenn kein State-Feature
     *
     * @example
     * // Standard state
     * $config = $this->getStateConfiguration('state');
     * // Ergebnis: ['type' => 'switch', 'values' => ['ON', 'OFF'], ...]
     *
     * // Vordefinierter state
     * $config = $this->getStateConfiguration('valve_state');
     * // Ergebnis: Konfiguration aus stateDefinitions
     */
    private function getStateConfiguration(string $featureId, ?array $feature = null): ?array
    {
        // Basis state-Pattern
        $statePattern = '/^state(?:_[a-z0-9]+)?$/i';

        if (preg_match($statePattern, $featureId)) {
            // Prüfe Schreibzugriff im Feature
            $isSwitchable = isset($feature['access']) && ($feature['access'] & 0b010) != 0;

            // Nutze existierende Funktion für Identifier-Konvertierung
            $normalizedId = $featureId;

            $this->SendDebug(__FUNCTION__, "State-Konfiguration für: $normalizedId", 0);

            return [
                'type' => 'switch',
                'dataType' => VARIABLETYPE_BOOLEAN,
                'values' => ['ON', 'OFF'],
                'profile' => '~Switch',
                'enableAction' => $isSwitchable,
                'ident' => $normalizedId
            ];
        }

        return isset(static::$stateDefinitions[$featureId])
            ? static::$stateDefinitions[$featureId]
            : null;
    }

    /**
     * Erzeugt den vollständigen Namen eines Variablenprofils basierend auf den Expose-Daten.
     *
     * Diese Methode generiert den vollständigen Namen eines Variablenprofils für ein bestimmtes Feature
     * (Expose). Falls das Feature minimale und maximale Werte (`value_min`, `value_max`) enthält, werden
     * diese in den Profilnamen integriert.
     *
     * @param array $feature Ein Array, das die Eigenschaften des Features enthält.
     * @return string Der vollständige Name des Variablenprofils.
     */
    private function getFullRangeProfileName($feature)
    {
        $name = 'Z2M.' . $feature['name'];
        $valueMin = $feature['value_min'] ?? null;
        $valueMax = $feature['value_max'] ?? null;

        if ($valueMin !== null && $valueMax !== null) {
            $name .= '_' . $valueMin . '_' . $valueMax;
        }

        return $name;
    }

    /**
     * Handhabt die Erstellung eines Zustandsmusters (State Mapping) für ein gegebenes Identifikator.
     *
     * Diese Methode überprüft, ob ein Zustandsmuster für den angegebenen Identifikator existiert.
     * Wenn ja, wird ein entsprechendes Profil erstellt und registriert. Das Profil enthält zwei Zustände,
     * die aus den vordefinierten Zustandsdefinitionen (`stateDefinitions`) abgeleitet werden.
     *
     * @param string $ident Der Identifikator, für den das Zustandsmuster erstellt werden soll.
     * @return string|null Der Name des erstellten Profils oder null, wenn kein Zustandsmuster existiert.
     */
    private function handleStateMapping(string $ident): ?string
    {
        if (!isset(self::$stateDefinitions[$ident])) {
            return null;
        }

        $stateInfo = self::$stateDefinitions[$ident];
        $this->RegisterProfileStringEx(
            $ident,
            '',
            '',
            '',
            [
                [$stateInfo['values'][0], $this->Translate($stateInfo['values'][0]), '', 0xFF0000],
                [$stateInfo['values'][1], $this->Translate($stateInfo['values'][1]), '', 0x00FF00]
            ]
        );

        $this->SendDebug(__FUNCTION__, "State mapping profile created for: $ident", 0);
        return $ident;
    }

    /**
     * Erzeugt ein Zustandsmuster (State Pattern) basierend auf dem angegebenen Typ.
     *
     * Diese Methode gibt ein reguläres Ausdrucksmuster zurück, das verwendet werden kann,
     * um Zustandsinformationen (State) zu verarbeiten. Der Typ des Musters kann entweder
     * 'MQTT' oder 'SYMCON' sein, um unterschiedliche Anwendungsfälle abzudecken.
     *
     * @param string $type Der Typ des Musters, das erzeugt werden soll. Mögliche Werte sind 'MQTT' und 'SYMCON'.
     *                     Standardwert ist 'MQTT'.
     *
     * @return string Das reguläre Ausdrucksmuster für den angegebenen Typ.
     */
    private static function buildStatePattern(string $type = 'MQTT'): string
    {
        return self::STATE_PATTERN[$type];
    }

// Abstrakte Methoden

    /**
     * Muss überschrieben werden
     * Fragt Exposes ab und verarbeitet die Antwort.
     *
     * @return bool
     */
    abstract protected function UpdateDeviceInfo(): ?bool;
}
