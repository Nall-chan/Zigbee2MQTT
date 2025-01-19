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
 *
 * Pseudo Variablen, welche über BufferHelper und die Magic-Functions __get und __set
 * direkt typensichere Werte, Arrays und Objekte in einem Instanz-Buffer schreiben und lesen können.
 * @property bool $BUFFER_MQTT_SUSPENDED Zugriff auf den Buffer für laufende Migration
 * @property bool $BUFFER_PROCESSING_MIGRATION Zugriff auf den Buffer für MQTT Nachrichten nicht verarbeiten
 * @property string $lastPayload Zugriff auf den Buffer welcher das Letzte Payload enthält (für Download-Button)
 * @property string $missingTranslations Zugriff auf den Buffer welcher ein array von fehlenden Übersetzungen enthält (für Download-Button)
 */
abstract class ModulBase extends \IPSModule
{
    use BufferHelper;
    use Semaphore;
    use ColorHelper;
    use VariableProfileHelper;
    use SendData;
    private const MINIMAL_MODUL_VERSION = 5.0;

    /**
     * @var array STATE_PATTERN
     * Definiert Nomenklatur für State-Variablen
     *      KEY:
     *      - BASE     'state' (Basisbezeichner)
     *      - SUFFIX:   Zusatzbezeichner
     *          - NUMERIC:   statel1, state_l1, StateL1, state_L1
     *          - DIRECTION: state_left, state_right, State_Left
     *          - COMBINED:  state_left_l1, State_Right_L1
     *      - MQTT:    Validiert MQTT-Payload (state, state_l1)
     *      - SYMCON:  Validiert Symcon-Variablen (state, State, statel1, state_l1, State_Left, state_right_l1)
     */
    private const STATE_PATTERN = [
        'PREFIX' => '',
        'BASE'   => 'state',
        'SUFFIX' => [
            'NUMERIC'   => '_[0-9]+',
            'DIRECTION' => '_(?:left|right)',
            'COMBINED'  => '_(?:left|right)_[0-9]+'
        ],
        'MQTT'   => '/^state(?:_[a-z0-9]+)?$/i',  // Für MQTT-Payload
        'SYMCON' => '/^[Ss]tate(?:_?(?:[Ll][0-9]+)|(?:[Ll]eft|[Rr]ight)(?:[Ll][0-9]+)?)?$/'
    ];

    /**
     * @var array FLOAT_UNITS
     * Entscheidet über Float oder Integer profile
     */
    private const FLOAT_UNITS = [
        '°C',
        '°F',
        'K',
        'mg/L',
        'g/m³',
        'mV',
        'V',
        'kV',
        'µV',
        'A',
        'mA',
        'µA',
        'W',
        'kW',
        'MW',
        'GW',
        'Wh',
        'kWh',
        'MWh',
        'GWh',
        'Hz',
        'kHz',
        'MHz',
        'GHz',
        'cd',
        'pH',
        'm',
        'cm',
        'mm',
        'µm',
        'nm',
        'l',
        'ml',
        'dl',
        'm³',
        'cm³',
        'mm³',
        'g',
        'kg',
        'mg',
        'µg',
        'ton',
        'lb',
        's',
        'ms',
        'µs',
        'ns',
        'min',
        'h',
        'd',
        'rad',
        'sr',
        'Bq',
        'Gy',
        'Sv',
        'kat',
        'mol',
        'mol/l',
        'N',
        'Pa',
        'kPa',
        'MPa',
        'GPa',
        'bar',
        'mbar',
        'atm',
        'torr',
        'psi',
        'ohm',
        'kohm',
        'mohm',
        'S',
        'mS',
        'µS',
        'F',
        'mF',
        'µF',
        'nF',
        'pF',
        'H',
        'mH',
        'µH',
        '%',
        'dB',
        'dBA',
        'dBC',
        'dB/m'
    ];

    /**
     * Liste bekannter Abkürzungen, die bei der Konvertierung von Identifikatoren
     * in snake_case beibehalten werden sollen.
     *
     * Diese Konstante wird im convertToSnakeCase() verwendet, um sicherzustellen,
     * dass gängige Abkürzungen (z.B. CO2, LED) korrekt formatiert werden.
     *
     * @var string[]
     */
    private const KNOWN_ABBREVIATIONS = [
        'VOC',
        'CO2',
        'PM25',
        'LED',
        'RGB',
        'HSV',
        'HSL',
        'XY',
        'MV',
        'KV',
        'MA',
        'KW',
        'MW',
        'GW',
        'kWH',
        'MWH',
        'GWH',
        'KHZ',
        'MHZ',
        'GHZ',
        'PH',
        'KPA',
        'MPA',
        'GPA',
        'MS',
        'MF',
        'NF',
        'PF',
        'MH',
        'DB',
        'DBA',
        'DBC'
    ];

    /**
     * @var string[]
     * Liste von alten Z2M Idents, welche bei der Konvertierung übersprungen werden müssen
     * damit sie erhalten bleiben.
     * Weil sich entweder der VariablenTyp ändert, oder der alte Name nicht konvertiert werden kann.
     * z.B. Z2M_ActionTransTime, was eigentlich action_transition_time ist.
     */
    private const SKIP_IDENTS = [
        'Z2M_ActionTransaction',
        'Z2M_ActionTransTime',
        'Z2M_XAxis',
        'Z2M_YAxis',
        'Z2M_ZAxis'
    ];

    /**
     * @var string $ExtensionTopic
     * Muss überschrieben werden.
     * - für den ReceiveFilter
     * - für LoadDeviceInfo
     * - überall wo das Topic der Extension genutzt wird
     *
     */
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
        ['group_type' => 'cover', 'feature' => 'position_left', 'profile' => '~Shutter.Reversed', 'variableType' => VARIABLETYPE_INTEGER],
        ['group_type' => 'cover', 'feature' => 'position_right', 'profile' => '~Shutter.Reversed', 'variableType' => VARIABLETYPE_INTEGER],
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
        ['group_type' => '', 'feature' => 'child_lock', 'profile' => '~Lock', 'variableType' => VARIABLETYPE_BOOLEAN],
        ['group_type' => '', 'feature' => 'window_open', 'profile' => '~Window', 'variableType' => VARIABLETYPE_BOOLEAN],
        ['group_type' => '', 'feature' => 'valve', 'profile' => '~Valve', 'variableType' => VARIABLETYPE_INTEGER],
        ['group_type' => '', 'feature' => 'window_detection', 'profile' =>'~Window', 'variableType' => VARIABLETYPE_BOOLEAN],
        ['group_type' => 'light', 'feature' => 'color', 'profile' => '~HexColor', 'variableType' => VARIABLETYPE_INTEGER],
        ['group_type' => 'climate', 'feature' => 'occupied_heating_setpoint', 'profile' => '~Temperature.Room', 'variableType' => VARIABLETYPE_FLOAT]
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
     *   - name: string Anzeigename der Variable -> @todo Wozu? Wird in registerSpecialVariable nicht genutzt
     *   - profile: string Profilname oder leer
     *   - scale?: float Optional: Skalierungsfaktor -> @todo Wozu? Wird in adjustSpecialValue nicht genutzt.  last_seen ist dort hart kodiert
     *   - ident?: string Optional: Benutzerdefinierter Identifier -> @todo Wozu? Wird in registerSpecialVariable nicht genutzt.
     *   - enableAction: bool Aktionen erlaubt (true/false)
     */
    protected static $specialVariables = [
        'last_seen'          => ['type' => VARIABLETYPE_INTEGER, 'name' => 'Last Seen', 'profile' => '~UnixTimestamp', 'scale' => 0.001, 'enableAction' => false],
        'color_mode'         => ['type' => VARIABLETYPE_STRING, 'name' => 'Color Mode', 'profile' => '', 'enableAction' => false],
        'update'             => ['type' => VARIABLETYPE_STRING, 'name' => 'Firmware Update Status', 'profile' => '', 'enableAction' => false],
        'device_temperature' => ['type' => VARIABLETYPE_FLOAT, 'name' => 'Device Temperature', 'profile' => '~Temperature', 'enableAction' => false],
        'brightness'         => ['type' => VARIABLETYPE_INTEGER, 'ident' => 'brightness', 'profile' => '~Intensity.100', 'scale' => 1, 'enableAction' => true],
        'brightness_l1'      => ['type' => VARIABLETYPE_INTEGER, 'name' => 'brightness_l1', 'profile' => '~Intensity.100', 'scale' => 1, 'enableAction' => true],
        'brightness_l2'      => ['type' => VARIABLETYPE_INTEGER, 'name' => 'brightness_l2', 'profile' => '~Intensity.100', 'scale' => 1, 'enableAction' => true],
        'voltage'            => ['type' => VARIABLETYPE_FLOAT, 'ident' => 'voltage', 'profile' => '~Volt', 'enableAction' => false],
        // Folgende Variablen waren früher ein anderer Typ, als jetzt automatisch erkannt wird.
        // Aus gründen der Kompatibilität werden diese zwangsweise auf den Typ festgelegt.
        // @todo
        // Leider werden hier aktuell nur StandardProfile unterstützt -> Fehler bei Z2M. Profilen
        // Ebenso wird bei nicht gesetzten enableAction nicht access aus dem exposes genutzt.
        // Dabei ist calibration_time je nach Gerät mal bedienbar und mal nicht. Jetzt immer nicht bedienbar
        'calibration_time'   => ['type' => VARIABLETYPE_FLOAT, 'profile' => 'Z2M.calibration_time'],
        'countdown'          => ['type' => VARIABLETYPE_INTEGER, 'profile' => 'Z2M.countdown_0_43200', 'enableAction' => true],
        'countdown_l1'       => ['type' => VARIABLETYPE_INTEGER, 'profile' => 'Z2M.countdown_0_43200', 'enableAction' => true],
        'countdown_l2'       => ['type' => VARIABLETYPE_INTEGER, 'profile' => 'Z2M.countdown_0_43200', 'enableAction' => true],
    ];

    /**
     * @var array $stateDefinitions Array mit Status-Definitionen
     *
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
     */
    protected static $stateDefinitions = [
        'auto_lock'   => ['type' => 'automode', 'dataType' => VARIABLETYPE_STRING, 'values' => ['AUTO', 'MANUAL']],
        'valve_state' => ['type' => 'valve', 'dataType' => VARIABLETYPE_STRING, 'values' => ['OPEN', 'CLOSED']],
    ];

    /**
     *  @var array $stringVariablesNoResponse
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
     * Create
     *
     * Wird einmalig beim Erstellen einer Instanz aufgerufen
     *
     * Führt folgende Aktionen aus:
     * - Verbindet mit der erstbesten MQTT-Server-Instanz
     * - Registriert Properties für MQTT-Basis-Topic und MQTT-Topic
     * - Initialisiert TransactionData Array
     * - Erstellt Zigbee2MQTTExposes Verzeichnis wenn nicht vorhanden
     * - Prüft und erstellt JSON-Datei für Geräteinfos
     *
     * @return void
     *
     * @throws \Exception Error on create Expose Directory
     *
     * @see \IPSModule::RegisterPropertyString()
     * @see \IPSModule::RegisterAttributeFloat()
     * @see \Zigbee2MQTT\ModulBase::createExposesDirectory()
     * @see \Zigbee2MQTT\ModulBase::RegisterProfileBoolean()
     */
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString(self::MQTT_BASE_TOPIC, '');
        $this->RegisterPropertyString(self::MQTT_TOPIC, '');
        $this->RegisterAttributeFloat(self::ATTRIBUTE_MODUL_VERSION, 5.0);

        /** Init Buffers */
        $this->BUFFER_MQTT_SUSPENDED = true;
        $this->BUFFER_PROCESSING_MIGRATION = false;
        $this->TransactionData = [];
        $this->lastPayload = [];

        $this->createExposesDirectory();

        // Statische Profile
        $this->RegisterProfileBooleanEx(
            'Z2M.DeviceStatus',
            'Network',
            '',
            '',
            [
                [false, 'Offline', '', 0xFF0000],
                [true, 'Online', '', 0x00FF00]
            ]
        );
        $this->RegisterMessage($this->InstanceID, IM_CHANGESTATUS);
        $this->RegisterMessage($this->InstanceID, FM_CONNECT);
    }

    /**
     * Destroy
     *
     * Diese Methode wird aufgerufen, wenn die Instanz gelöscht,
     * oder Symcon beendet wird.
     *
     * Sie sorgt dafür, dass die zugehörige .json-Datei entfernt wird.
     *
     * Achtung: Methoden vom ipsmodule:: sind hier nicht mehr verfügbar!
     * Darum muss hier IPS_LogMessage benutzt werden.
     * Entsprechend ist auch kein Translate mehr verfügbar!
     *
     * @see \IPSModule::Destroy()
     * @see IPS_InstanceExists()
     * @see IPS_LogMessage()
     *
     * @return void
     */
    public function Destroy()
    {
        // Nur wenn Instanz gelöscht wurde.
        if (!IPS_InstanceExists($this->InstanceID)) {
            // Vollständiger Pfad zur JSON-Datei
            $jsonFile = IPS_GetKernelDir() . self::EXPOSES_DIRECTORY . DIRECTORY_SEPARATOR . $this->InstanceID . '.json';
            // Überprüfung und Löschung der Datei
            if (is_file($jsonFile)) {
                if (unlink($jsonFile)) {
                    // us
                    IPS_LogMessage(__CLASS__, 'File successfully deleted: ' . $jsonFile);
                } else {
                    IPS_LogMessage(__CLASS__, 'Error on delete file: ' . $jsonFile);
                }
            }
        }
        parent::Destroy();
    }

    /**
     * ApplyChanges
     *
     * Wird aufgerufen bei übernehmen der Modulkonfiguration
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
     * @see \IPSModule::ApplyChanges()
     * @see \IPSModule::ReadPropertyString()
     * @see \IPSModule::SetReceiveDataFilter()
     * @see \IPSModule::HasActiveParent()
     * @see \IPSModule::GetStatus()
     * @see \IPSModule::SetStatus()
     * @see \Zigbee2MQTT\ModulBase::checkAndCreateJsonFile()
     * @see IPS_GetKernelRunlevel()
     */
    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

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
        $Filter2 = preg_quote('"Topic":"' . $BaseTopic . self::SYMCON_EXTENSION_RESPONSE . static::$ExtensionTopic . $MQTTTopic);
        $this->SendDebug('Filter', '.*(' . $Filter1 . '|' . $Filter2 . ').*', 0);
        $this->SetReceiveDataFilter('.*(' . $Filter1 . '|' . $Filter2 . ').*');
        $this->SetStatus(IS_ACTIVE);
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
            case FM_CONNECT:
                if ($this->GetStatus() == IS_ACTIVE) {
                    $this->BUFFER_MQTT_SUSPENDED = false;
                }
                if (($this->HasActiveParent()) && (IPS_GetKernelRunlevel() == KR_READY)) {
                    $this->LogMessage('FM_CONNECT', KL_NOTIFY);
                    $this->checkAndCreateJsonFile();
                }
                break;
            case IM_CHANGESTATUS:
                if ($Data[0] == IS_ACTIVE) {
                    $this->LogMessage('IM_CHANGESTATUS', KL_NOTIFY);
                    $this->BUFFER_MQTT_SUSPENDED = false;
                    // Nur ein UpdateDeviceInfo wenn Parent aktiv und System bereit
                    if (($this->HasActiveParent()) && (IPS_GetKernelRunlevel() == KR_READY)) {
                        $this->checkAndCreateJsonFile();
                    }
                }
                return;
        }
    }

    /**
     * RequestAction
     *
     * Verarbeitet Aktionsanforderungen für Variablen
     *
     * Diese Methode wird automatisch aufgerufen, wenn eine Aktion einer Variable
     * oder IPS_RequestAction ausgeführt wird.
     *
     * Sie verarbeitet verschiedene Arten von Aktionstypen:
     *
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
     * @see \IPSModule::RequestAction()
     * @see \IPSModule::SendDebug()
     * @see \Zigbee2MQTT\ModulBase::UpdateDeviceInfo()
     * @see \Zigbee2MQTT\ModulBase::handlePresetVariable()
     * @see \Zigbee2MQTT\ModulBase::handleStringVariableNoResponse()
     * @see \Zigbee2MQTT\ModulBase::handleColorVariable()
     * @see \Zigbee2MQTT\ModulBase::handleStateVariable()
     * @see \Zigbee2MQTT\ModulBase::handleStandardVariable()
     * @see json_encode()
     */
    public function RequestAction($ident, $value)
    {
        $this->SendDebug(__FUNCTION__, 'Aufgerufen für Ident: ' . $ident . ' mit Wert: ' . json_encode($value), 0);

        $handled = match (true) {
            //Behandelt UpdateInfo
            $ident == 'UpdateInfo' => $this->UpdateDeviceInfo(),
            // Behandelt Presets
            strpos($ident, 'presets') !== false => $this->handlePresetVariable($ident, $value),
            // Behandelt String-Variablen ohne Rückmeldung
            in_array($ident, self::$stringVariablesNoResponse) => $this->handleStringVariableNoResponse($ident, (string) $value),
            // Behandelt Farbvariablen
            strpos($ident, 'color') === 0 => $this->handleColorVariable($ident, $value),
            // Behandelt Status-Variablen
            preg_match(self::STATE_PATTERN['SYMCON'], $ident) => $this->handleStateVariable($ident, $value),
            // Behandelt Standard-Variablen
            default => $this->handleStandardVariable($ident, $value),
        };
        // Debug-Ausgabe bei fehlerhafter oder fehlender Aktion
        if ($handled === false) {
            $this->SendDebug(__FUNCTION__, 'Fehler beim verarbeiten der Aktion: ' . $ident, 0);
        }
    }

    /**
     * ReceiveData
     *
     * Verarbeitet eingehende MQTT-Nachrichten
     *
     * Diese Methode wird automatisch aufgerufen, wenn eine MQTT-Nachricht empfangen wird.
     * Der Verarbeitungsablauf ist wie folgt:
     * 1. Prüft ob die Instanz noch bei der Migration ist
     * 2. Prüft ob Instanz im CREATE-Status ist
     * 3. Lässt den JSONString prüfen und zerlegen
     * 4. Verarbeitet spezielle Nachrichtentypen:
     *    - Verfügbarkeitsstatus (availability)
     *    - Symcon Extension Antworten
     * 5. Wenn keine spezielle Nachricht, dann Payload verarbeiten lassen
     *
     * @param string $JSONString Die empfangene MQTT-Nachricht im JSON-Format
     *
     * @return string Leerer String als Rückgabewert
     *
     * @see \IPSModule::ReceiveData()
     * @see \IPSModule::GetBuffer()
     * @see \IPSModule::GetStatus()
     * @see \Zigbee2MQTT\ModulBase::validateAndParseMessage()
     * @see \Zigbee2MQTT\ModulBase::handleAvailability()
     * @see \Zigbee2MQTT\ModulBase::handleSymconExtensionResponses()
     * @see \Zigbee2MQTT\ModulBase::processPayload()
     */
    public function ReceiveData($JSONString)
    {
        // Während Migration keine MQTT Nachrichten verarbeiten
        if ($this->BUFFER_MQTT_SUSPENDED) {
            return '';
        }
        // Instanz im CREATE-Status überspringen
        if ($this->GetStatus() == IS_CREATING) {
            return '';
        }
        // JSON-Nachricht dekodieren
        [$topics, $payload] = $this->validateAndParseMessage($JSONString);
        if (!$topics) {
            return '';
        }
        // Behandelt Verfügbarkeitsstatus
        if ($this->handleAvailability($topics, $payload)) {
            return '';
        }
        // Leere Payloads brauchte nur handleAvailability
        if (is_null($payload)) {
            return '';
        }

        // Behandelt Symcon Extension Antworten, auch wenn Instanz noch in IS_CREATING ist.
        if ($this->handleSymconExtensionResponses($topics, $payload)) {
            return '';
        }
        // Verarbeitet Payload
        $this->processPayload($payload);
        return '';
    }

    /**
     * Migrate
     *
     * Prüft über ein Attribute ob die Modul-Instanz ein Update benötigt.
     *
     * Führt anschließend eine Migration von Objekt-Idents durch, indem es Kinder-Objekte dieser Instanz durchsucht,
     * auf definierte Kriterien überprüft und bei Bedarf umbenennt.
     *
     * - Überprüfung, ob der Ident mit "Z2M_" beginnt
     * - Konvertierung des Ident ins snake_case
     * - Loggt sowohl Fehler als auch erfolgreiche Änderungen
     *
     * @param string $JSONData JSON-Daten mit allen Properties und Attributen
     * @return string JSON-Daten mit allen Properties und Attributen
     *
     * @see \IPSModule::Migrate()
     * @see \IPSModule::SetBuffer()
     * @see \IPSModule::LogMessage()
     * @see IPS_GetChildrenIDs()
     * @see IPS_GetObject()
     * @see IPS_SetIdent()
     * @see json_decode()
     * @see json_encode()
     */
    public function Migrate($JSONData)
    {
        // Prüfe Version diese Modul-Instanz
        $j = json_decode($JSONData);
        if (isset($j->attributes->{self::ATTRIBUTE_MODUL_VERSION})) {
            if ($j->attributes->{self::ATTRIBUTE_MODUL_VERSION} >= self::MINIMAL_MODUL_VERSION) {
                return $JSONData;
            }
        }
        $j->attributes->{self::ATTRIBUTE_MODUL_VERSION} = self::MINIMAL_MODUL_VERSION;

        // Flag für laufende Migration setzen
        $this->BUFFER_MQTT_SUSPENDED = true;
        $this->BUFFER_PROCESSING_MIGRATION = true;

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

            if ($obj['ObjectIdent'] == '') {
                // Hat keinen Ident, also ignorieren
                continue;
            }

            // Nur solche Idents, die mit 'Z2M_' beginnen:
            if (substr($obj['ObjectIdent'], 0, 4) !== 'Z2M_') {
                // Überspringen
                continue;
            }
            if (in_array($obj['ObjectIdent'], self::SKIP_IDENTS)) {
                // Überspringen
                continue;
            }
            // Neuen Ident bilden
            $newIdent = self::convertToSnakeCase($obj['ObjectIdent']);
            // Versuchen zu setzen
            $result = @IPS_SetIdent($childID, $newIdent);
            if ($result === false) {
                $this->LogMessage(__FUNCTION__ . ' : Fehler: Ident "' . $newIdent . '" konnte nicht für Variable #' . $childID . ' gesetzt werden!', KL_ERROR);
            } else {
                $this->LogMessage(__FUNCTION__ . ' : Variable #' . $childID . ': "' . $obj['ObjectIdent'] . '" wurde geändert zu "' . $newIdent . '"', KL_NOTIFY);
            }
        }

        // Brightness Profil Migration
        $varID = @$this->GetIDForIdent('brightness');
        if ($varID !== false) {
            $this->RegisterVariableInteger(
                'brightness',
                $this->Translate('Brightness'),
                '~Intensity.100',
                10
            );
            $this->EnableAction('brightness');
        }
        // Flag für beendete Migration wieder setzen
        $this->BUFFER_MQTT_SUSPENDED = false;
        $this->BUFFER_PROCESSING_MIGRATION = false;
        return json_encode($j);
    }

    /**
     * SendSetCommand
     *
     * Sendet einen Set-Befehl an das Gerät über MQTT
     *
     * Diese Methode generiert das MQTT-Topic für den Set-Befehl basierend auf der Konfiguration
     * und sendet das übergebene Array über SendData an das Gerät.
     *
     * @param array $Payload Array mit Schlüssel-Wert-Paaren, das an das Gerät gesendet werden soll
     *
     * @return bool True wenn die Daten versendet werden konnten, sonst false
     *
     * @throws \Exception Bei Fehlern während des Sendens
     *
     * @see \IPSModule::ReadPropertyString()
     * @see \IPSModule::SendDebug()
     * @see \Zigbee2MQTT\ModulBase::SendData()
     * @see json_encode()
     */
    public function SendSetCommand(array $Payload): bool
    {
        // MQTT-Topic für den Set-Befehl generieren
        $Topic = '/' . $this->ReadPropertyString(self::MQTT_TOPIC) . '/set';

        // Debug-Ausgabe des zu sendenden Payloads
        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: zu sendendes Payload: ', json_encode($Payload), 0);

        // Sende die Daten an das Gerät
        return $this->SendData($Topic, $Payload, 0);
    }

    /**
     * SetColorExt
     *
     * Ermöglicht es eine Farbe (INT) mit Transition zu setzen.
     *
     * @param  int $color
     * @param  int $Transition
     * @return bool
     */
    public function SetColorExt(int $color, int $TransitionTime): bool
    {
        return $this->setColor($color, 'cie', 'color', $TransitionTime);
    }

    /**
     * UIExportDebugData
     *
     * @return string
     */
    public function UIExportDebugData(): string
    {
        $DebugData = [];
        $DebugData['Instance'] = IPS_GetObject($this->InstanceID) + IPS_GetInstance($this->InstanceID);
        if (IPS_GetInstance($this->InstanceID)['ModuleInfo']['ModuleID'] == self::GUID_MODULE_DEVICE) {
            $DebugData['Model'] = $this->ReadAttributeString('Model');
            $ModelUrl = str_replace([' ', '/'], '_', $DebugData['Model']);
            $DebugData['ModelUrl'] = 'https://www.zigbee2mqtt.io/devices/' . rawurlencode($ModelUrl) . '.html';
        }
        $DebugData['Config'] = json_decode(IPS_GetConfiguration($this->InstanceID), true);
        $jsonFile = IPS_GetKernelDir() . self::EXPOSES_DIRECTORY . DIRECTORY_SEPARATOR . $this->InstanceID . '.json';

        // Prüfe ob JSON existiert
        if (file_exists($jsonFile)) {
            $DebugData['Exposes'] = json_decode(file_get_contents($jsonFile), true)['exposes'];
        } else {
            $DebugData['Exposes'] = [];
        }
        $DebugData['LastPayload'] = $this->lastPayload;
        $DebugData['Childs'] = [];
        $DebugData['Profile'] = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $obj = IPS_GetObject($childID);
            if ($obj['ObjectType'] !== OBJECTTYPE_VARIABLE) {
                continue;
            }
            $var = IPS_GetVariable($childID);
            $DebugData['Childs'][$childID] = IPS_GetObject($childID) + $var;
            if ($var['VariableCustomProfile'] != '') {
                $DebugData['Profile'][$var['VariableCustomProfile']] = IPS_GetVariableProfile($var['VariableCustomProfile']);
            }
            if ($var['VariableProfile'] != '') {
                $DebugData['Profile'][$var['VariableProfile']] = IPS_GetVariableProfile($var['VariableProfile']);
            }
        }

        return 'data:application/json;base64,' . base64_encode(json_encode($DebugData, JSON_PRETTY_PRINT));
    }

    /**
     * Translate
     *
     * Überschreibt Translate um die Übersetzung aus der globalen json zu nutzen.
     *
     * @param  string $Text
     * @return string
     */
    public function Translate($Text)
    {
        $translation = array_merge_recursive(
            json_decode(file_get_contents(__DIR__ . '/locale.json'), true),
            json_decode(file_get_contents(__DIR__ . '/locale_z2m.json'), true)
        );
        $language = IPS_GetSystemLanguage();
        $code = explode('_', $language)[0];
        if (isset($translation['translations'])) {
            if (isset($translation['translations'][$language])) {
                if (isset($translation['translations'][$language][$Text])) {
                    return $translation['translations'][$language][$Text];
                }
            } elseif (isset($translation['translations'][$code])) {
                if (isset($translation['translations'][$code][$Text])) {
                    return $translation['translations'][$code][$Text];
                }
            }
        }
        return $Text;
    }

    // Variablenmanagement

    /**
     * SetValue
     *
     * Setzt den Wert einer Variable unter Berücksichtigung verschiedener Typen und Formatierungen
     *
     * Verarbeitung:
     * 1. Prüft Existenz der Variable, Abbruch wenn Variable nicht vorhanden
     * 2. Konvertiert Wert entsprechend Variablentyp (adjustValueByType)
     * 3. Wendet Profilzuordnungen an
     * 4. Behandelt Spezialfälle (z.B. ColorTemp, Color)
     *
     * Unterstützte Variablentypen:
     * 1. State-Variablen:
     *    - state: ON/OFF -> true/false
     *    - stateL1: Nummerierte States
     *    - stateLeft: Richtungs-States
     *    - stateLeftL1: Kombinierte States
     *
     * 2. Spezielle Variablen:
     *    - color: RGB-Farbwerte oder XY-Farbwerte mit Brightness
     *      Format RGB: Integer (0xRRGGBB)
     *      Format XY: Array ['x' => float, 'y' => float, 'brightness' => int]
     *    - color_temp: Farbtemperatur mit Kelvin-Konvertierung
     *    - preset: Vordefinierte Werte
     *
     * 3. Standard-Variablen:
     *    - Boolean: Automatische ON/OFF Konvertierung
     *    - Integer/Float: Typkonvertierung mit Einheitenbehandlung
     *    - String: Direkte Wertzuweisung
     *
     * @param string $ident Identifier der Variable (z.B. "state", "color_temp", "color")
     * @param mixed $value Zu setzender Wert
     *                    Bool: true/false oder "ON"/"OFF"
     *                    Int/Float: Numerischer Wert
     *                    String: Textwert
     *                    Array: Spezielle Behandlung für Farben und Presets
     *                    Array: Rest Wird ignoriert (Todo: Warum? Was ist mit UpdateStatus?)
     *
     * @return void
     *
     * Beispiel:
     * ```php
     * // States
     * $this->SetValue("state", "ON");         // Setzt bool true
     * $this->SetValue("stateL1", false);      // Setzt "OFF"
     *
     * // Farben & Temperatur
     * $this->SetValue("color_temp", 4000);    // Setzt Farbtemp + Kelvin
     * $this->SetValue("color", 0xFF0000);     // Setzt Rot als RGB
     * $this->SetValue("color", [              // Setzt Farbe im XY Format
     *     'x' => 0.7006,
     *     'y' => 0.2993,
     *     'brightness' => 254
     * ]);
     *
     * // Profile
     * $this->SetValue("mode", "auto");        // Nutzt Profilzuordnung
     * ```
     *
     * @see \Zigbee2MQTT\ModulBase::adjustValueByType()
     * @see \Zigbee2MQTT\ModulBase::convertMiredToKelvin()
     * @see \Zigbee2MQTT\ModulBase::handleColorVariable()
     * @see \Zigbee2MQTT\ModulBase::SetValueDirect()
     * @see \IPSModule::SetValue()
     * @see \IPSModule::GetIDForIdent()
     * @see \IPSModule::SendDebug()
     * @see is_array()
     * @see json_encode()
     * @see IPS_GetVariable()
     * @see IPS_VariableProfileExists()
     * @see IPS_GetVariableProfile()
     */
    protected function SetValue($ident, $value)
    {
        $variableID = @$this->GetIDForIdent($ident);
        if (!$variableID) {
            $this->SendDebug(__FUNCTION__, 'Variable nicht gefunden: ' . $ident, 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'Verarbeite Variable: ' . $ident . ' mit Wert: ' . json_encode($value), 0);

        // Array Spezialbehandlung für
        if (is_array($value)) {
            // update Arrays
            if (strtolower($ident) === 'update') {
                $this->SendDebug(__FUNCTION__, 'Update Array empfangen', 0);
                $jsonValue = json_encode($value, JSON_PRETTY_PRINT);
                parent::SetValue($ident, $jsonValue);
                return;
            }
            // Color-Arrays
            if (strtolower($ident) === 'color') {
                $this->handleColorVariable($ident, $value);
                return;
            }
            $this->SendDebug(__FUNCTION__, 'Wert ist ein Array, übersprungen: ' . $ident, 0);
            return;
        }
        $var = IPS_GetVariable($variableID);
        $varType = $var['VariableType'];
        $adjustedValue = $this->adjustValueByType($var, $value);

        // Profilverarbeitung nur für nicht-boolesche Werte
        if ($varType !== 0) {
            $profileName = ($var['VariableCustomProfile'] != '' ? $var['VariableCustomProfile'] : $var['VariableProfile']);
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
     * SetValueDirect
     *
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
     * Beispiel:
     * ```php
     * // Boolean setzen
     * $this->SetValueDirect("state", true);
     *
     * // Array als JSON
     * $this->SetValueDirect("data", ["temp" => 22]);
     * ```
     *
     * @internal Diese Methode wird hauptsächlich intern verwendet für:
     *          - Direkte Wertzuweisung ohne Profile
     *          - Array zu JSON Konvertierung
     *          - Debug-Werte setzen
     *
     * @see \IPSModule::SetValue()
     * @see \IPSModule::GetIDForIdent()
     * @see \IPSModule::SendDebug()
     * @see is_array()
     * @see json_encode()
     * @see IPS_GetVariable()
     */
    protected function SetValueDirect(string $ident, mixed $value): void
    {
        $variableID = @$this->GetIDForIdent($ident);

        if (!$variableID) {
            $this->SendDebug(__FUNCTION__, 'Variable nicht gefunden: ' . $ident, 0);
            return;
        }

        // Typ-Prüfung und Konvertierung
        if (is_array($value)) {
            $this->SendDebug(__FUNCTION__, 'Array-Wert erkannt, konvertiere zu JSON', 0);
            $value = json_encode($value);
        }

        // Wert entsprechend Variablentyp konvertieren
        switch (IPS_GetVariable($variableID)['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $value = (bool) $value;
                $debugVarType = 'bool';
                break;
            case VARIABLETYPE_INTEGER:
                $value = (int) $value;
                $debugVarType = 'integer';
                break;
            case VARIABLETYPE_FLOAT:
                $value = (float) $value;
                $debugVarType = 'float';
                break;
            case VARIABLETYPE_STRING:
                $value = (string) $value;
                $debugVarType = 'string';
                break;
        }

        $this->SendDebug(__FUNCTION__, sprintf('Setze Variable: %s, Typ: %s, Wert: %s', $ident, $debugVarType, json_encode($value)), 0);
        // Setze den Wert der Variable
        parent::SetValue($ident, $value);
    }

    /**
     * AppendVariableTypes
     *
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
     *
     * @see \IPSModule::GetIDForIdent()
     * @see \IPSModule::SendDebug()
     * @see json_encode()
     * @see IPS_GetVariable()
     */
    protected function AppendVariableTypes(array $Payload): array
    {
        // Zeige das eingehende Payload im Debug
        $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Eingehendes Payload: ', json_encode($Payload), 0);
        $this->lastPayload = $this->lastPayload + $Payload;
        foreach ($Payload as $key => $value) {
            // Prüfe, ob die Variable existiert
            $objectID = @$this->GetIDForIdent($key);
            // $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Variable existiert für Ident: ', $ident, 0);
            if ($objectID) {
                // Füge dem Payload den Variablentyp als neuen Schlüssel hinzu
                $Payload[$key . '_type'] = IPS_GetVariable($objectID)['VariableType'];
            }
        }

        // Zeige das modifizierte Payload im Debug
        $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: modifizierter Payload mit Typen: ', json_encode($Payload), 0);

        // Gib das modifizierte Payload zurück
        return $Payload;
    }

    // Feature & Expose Handling

    /**
     * mapExposesToVariables
     *
     * Mappt die übergebenen Exposes auf Variablen und registriert diese.
     * Diese Funktion verarbeitet die übergebenen Exposes (z.B. Sensoreigenschaften) und registriert sie als Variablen.
     * Wenn ein Expose mehrere Features enthält, werden diese ebenfalls einzeln registriert.
     *
     * @param array $exposes Ein Array von Exposes, das die Geräteeigenschaften oder Sensoren beschreibt.
     *
     * @return void
     *
     * @see \Zigbee2MQTT\ModulBase::registerVariable()
     * @see \Zigbee2MQTT\ModulBase::convertLabelToName()
     * @see \Zigbee2MQTT\ModulBase::getVariableTypeFromProfile()
     * @see \Zigbee2MQTT\ModulBase::registerPresetVariables()
     * @see \IPSModule::SetBuffer()
     * @see \IPSModule::SendDebug()
     * @see is_array()
     * @see json_encode()
     */
    protected function mapExposesToVariables(array $exposes): void
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
                            $this->brightnessConfig = $brightnessConfig;
                            $this->SendDebug(__FUNCTION__, 'Brightness Config: ' . json_encode($brightnessConfig), 0);
                        }
                    }
                }
            } else {
                $this->registerVariable($expose);
                if (isset($expose['presets'])) {
                    $variableType = $this->getVariableTypeFromProfile($expose['type'], $expose['property'], $expose['unit'] ?? '', $expose['step'] ?? null, null);
                    $this->registerPresetVariables($expose['presets'], $expose['property'], $variableType, $expose);
                }
            }
        }
    }

    /**
     * LoadDeviceInfo
     *
     * Lädt die Geräte oder Gruppen Infos über die SymconExtension von Zigbee2MQTT
     *
     * @return array|false Enthält die Antwort als Array, oder false im Fehlerfall.
     *
     * @see \Zigbee2MQTT\ModulBase::SendData()
     * @see \IPSModule::ReadPropertyString()
     * @see \IPSModule::LogMessage()
     */
    protected function LoadDeviceInfo()
    {
        if (!$this->HasActiveParent()) {
            return false;
        }
        $mqttTopic = $this->ReadPropertyString(self::MQTT_TOPIC);
        if (empty($mqttTopic)) {
            $this->LogMessage($this->Translate('MQTTTopic not configured.'), KL_WARNING);
            return false;
        }
        $Result = $this->SendData(self::SYMCON_EXTENSION_REQUEST . static::$ExtensionTopic . $mqttTopic, [], 2500);
        return $Result;
    }

    /**
     * UpdateDeviceInfo
     *
     * Muss überschrieben werden
     * Muss die Exposes per LoadDeviceInfo laden und verarbeiten.
     *
     * @return bool
     */
    abstract protected function UpdateDeviceInfo(): bool;

    /**
     * SaveExposesToJson
     *
     * Speichert die Exposes in einer JSON-Datei.
     *
     * Die JSON-Datei wird im Format "InstanzID.json" im Verzeichnis "Zigbee2MQTTExposes" gespeichert
     * und enthält die Expose-Informationen des Zigbee-Geräts.
     *
     * @param  array $Result
     * @return bool
     *
     * @see \Zigbee2MQTT\ModulBase::createExposesDirectory()
     * @see \IPSModule::LogMessage()
     * @see json_encode()
     * @see file_put_contents()
     * @see IPS_GetKernelDir()
     */
    protected function SaveExposesToJson(array $Result): bool
    {
        // JSON-Daten mit Pretty-Print erstellen
        $jsonData = json_encode($Result, JSON_PRETTY_PRINT);
        if ($jsonData === false) {
            $this->LogMessage($this->Translate('JSON encoding error: ') . json_last_error_msg(), KL_ERROR);
            return false;
        }

        $this->createExposesDirectory();

        // Definieren des Verzeichnisnamens
        $jsonFile = IPS_GetKernelDir() . self::EXPOSES_DIRECTORY . DIRECTORY_SEPARATOR . $this->InstanceID . '.json';

        // Schreiben der JSON-Daten in die Datei
        if (file_put_contents($jsonFile, $jsonData) === false) {
            $this->LogMessage($this->Translate('Error writing JSON file.'), KL_ERROR);
            return false;
        }
        return true;
    }

    /**
     * createExposesDirectory
     *
     * @return void
     *
     * @throws \Exception Error on create Expose Directory
     *
     * @see is_dir()
     * @see mkdir()
     * @see IPS_GetKernelDir()
     */
    private function createExposesDirectory(): void
    {
        // Vollständigen Pfad zum Verzeichnis erstellen
        $ExposeDirectory = IPS_GetKernelDir() . self::EXPOSES_DIRECTORY;

        // Verzeichnis erstellen wenn nicht vorhanden
        if (!is_dir($ExposeDirectory)) {
            if (!mkdir($ExposeDirectory)) {
                throw new \Exception($this->Translate('Error on create Expose Directory'));
            }
        }
    }

    /**
     * convertToSnakeCase
     *
     * Diese Hilfsfunktion entfernt das Prefix "Z2M_" und
     * wandelt CamelCase in lower_snake_case um.
     *
     * Beispiele:
     * - "color_temp" -> "color_temp"
     * - "brightnessABC" -> "brightness_a_b_c"
     * @param  string $oldIdent
     * @return string
     *
     * @see preg_replace()
     * @see ltrim()
     * @see strtolower()
     */
    private static function convertToSnakeCase(string $oldIdent): string
    {
        // 1) Prefix "Z2M_" entfernen
        $withoutPrefix = preg_replace('/^Z2M_/', '', $oldIdent);

        // 2) Prüfe ob der Identifier dem MQTT-Pattern oder STATE_PATTERN entspricht
        foreach ([self::STATE_PATTERN['MQTT'], self::STATE_PATTERN['SYMCON']] as $pattern) {
            if (preg_match($pattern, $withoutPrefix)) {
                // Spezielles Handling für State-Pattern
                $result = preg_replace('/^(state)([LlRr][0-9]+)$/i', '$1_$2', $withoutPrefix);
                return strtolower($result);
            }
        }

        // 3) Prüfen ob der Identifier eine bekannte Abkürzung enthält
        foreach (self::KNOWN_ABBREVIATIONS as $abbr) {
            if (stripos($withoutPrefix, $abbr) !== false) {
                // Ersetze die Abkürzung durch ihre Kleinschreibung
                $withoutPrefix = str_ireplace($abbr, strtolower($abbr), $withoutPrefix);
            }
        }

        // 4) Vor jedem Großbuchstaben einen Unterstrich einfügen
        //    Bsp: "ColorTemp" -> "_Color_Temp"
        //    Bsp: "BrightnessABC" -> "_Brightness_A_B_C"
        $withUnderscore = preg_replace('/([A-Z])/', '_$1', $withoutPrefix);

        // 5) Falls jetzt am Anfang ein "_" ist, entfernen
        $withUnderscore = ltrim($withUnderscore, '_');

        // 6) Mehrere aufeinanderfolgende Unterstriche auf einen reduzieren
        $withUnderscore = preg_replace('/_+/', '_', $withUnderscore);

        // 7) Jetzt alles in kleingeschrieben
        $snakeCase = strtolower($withUnderscore);

        return $snakeCase;
    }

    // MQTT Kommunikation

    /**
     * validateAndParseMessage
     *
     * Dekodiert und validiert eine MQTT-JSON-Nachricht
     *
     * Verarbeitung:
     * - Dekodiert JSON-String in Array
     * - Prüft auf JSON-Decodierung-Fehler
     * - Validiert Vorhandensein des Topic-Felds
     * - Zerlegt Topic in Array
     *
     * @param string $JSONString Die zu dekodierende MQTT-Nachricht
     *
     * @return array Decodiertes Topic und Payload-Array oder false,false Array bei Fehlern
     *
     * @see \IPSModule::ReadPropertyString()
     * @see \IPSModule::SendDebug()
     * @see json_decode()
     * @see json_last_error()
     * @see json_last_error_msg()
     * @see substr()
     * @see strlen()
     * @see utf8_decode()
     */
    private function validateAndParseMessage(string $JSONString): array
    {
        $baseTopic = $this->ReadPropertyString(self::MQTT_BASE_TOPIC);
        $mqttTopic = $this->ReadPropertyString(self::MQTT_TOPIC);

        if (empty($baseTopic) || empty($mqttTopic)) {
            $this->SendDebug(__FUNCTION__, 'BaseTopic oder MQTTTopic ist leer', 0);
            return [false, false];
        }

        $messageData = json_decode($JSONString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SendDebug(__FUNCTION__, 'JSON Decodierung fehlgeschlagen: ' . json_last_error_msg(), 0);
            return [false, false];
        }

        if (!isset($messageData['Topic'])) {
            $this->SendDebug(__FUNCTION__, 'Topic nicht gefunden', 0);
            return [false, false];
        }

        $topic = substr($messageData['Topic'], strlen($baseTopic) + 1);
        return [
            explode('/', $topic),
            json_decode(mb_convert_encoding($messageData['Payload'], 'ISO-8859-1', 'UTF-8'), true) // wir nutzen bitte utf8_decode bei IPSModule, und hex2bin ab IPSModuleStrict
        ];
    }

    /**
     * handleAvailability
     *
     * Verarbeitet den Verfügbarkeitsstatus eines Zigbee-Geräts
     *
     * Funktionen:
     * - Prüft ob Topic ein Verfügbarkeits-Topic ist
     * - Erstellt/Aktualisiert Z2M.DeviceStatus Profil
     * - Registriert/Aktualisiert Verfügbarkeits-Variable
     *
     * @param array $topics Array mit Topic-Bestandteilen
     * @param array $payload Array mit MQTT-Nachrichtendaten
     *
     * @return bool True wenn Verfügbarkeit verarbeitet wurde, sonst False
     *
     * @see \Zigbee2MQTT\ModulBase::RegisterProfileBoolean()
     * @see \Zigbee2MQTT\ModulBase::RegisterVariableBoolean()
     * @see \IPSModule::Translate()
     * @see \IPSModule::SetValue()
     * @see end()
     */
    private function handleAvailability(array $topics, ?array $payload): bool
    {
        if (end($topics) !== self::AVAILABILITY_TOPIC) {
            return false;
        }
        $this->RegisterVariableBoolean('device_status', $this->Translate('Availability'), 'Z2M.DeviceStatus');
        if (isset($payload['state'])) {
            parent::SetValue('device_status', $payload['state'] == 'online');
        } else { // leeren Payload, wenn z.B. Gerät gelöscht oder umbenannt wurde
            parent::SetValue('device_status', false);
        }
        return true;
    }

    /**
     * handleSymconExtensionResponses
     *
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
     * @param array $payload Array mit MQTT-Nachrichtendaten
     *
     * @return bool True wenn eine Symcon-Antwort verarbeitet wurde, sonst False
     *
     * @see \Zigbee2MQTT\ModulBase::UpdateTransaction()
     * @see \IPSModule::ReadPropertyString()
     * @see implode()
     */
    private function handleSymconExtensionResponses(array $topics, array $payload): bool
    {
        $mqttTopic = $this->ReadPropertyString(self::MQTT_TOPIC);
        $fullTopic = '/' . implode('/', $topics);
        if ($fullTopic === self::SYMCON_EXTENSION_RESPONSE . static::$ExtensionTopic . $mqttTopic) {
            if (isset($payload['transaction'])) {
                $this->UpdateTransaction($payload);
            }
            return true;
        }
        return false;
    }

    /**
     * processPayload
     *
     * Verarbeitet die empfangenen MQTT-Payload-Daten
     *
     * @param array $payload Array mit den MQTT-Nachrichtendaten
     *
     * @return string Leerer String
     *
     * Beispiel:
     * ```php
     * // Verarbeitung einer MQTT-Nachricht
     * $messageData = ['temperature' => 21.5];
     * $result = $this->processPayload($messageData);
     * ```
     *
     * @internal Diese Methode wird von ReceiveData aufgerufen
     *
     * @see \Zigbee2MQTT\ModulBase::ReceiveData()
     * @see \Zigbee2MQTT\ModulBase::mapExposesToVariables()
     * @see \Zigbee2MQTT\ModulBase::AppendVariableTypes()
     * @see \Zigbee2MQTT\ModulBase::processSpecialVariable()
     * @see \Zigbee2MQTT\ModulBase::processVariable()
     * @see \IPSModule::SendDebug()
     * @see strpos()
     * @see is_array()
     * @see json_encode()
     */
    private function processPayload(array $payload): void
    {
        // Exposes verarbeiten wenn vorhanden
        if (isset($payload['exposes'])) {
            $this->mapExposesToVariables($payload['exposes']);
        }

        // Variablentypen anhängen
        $payloadWithTypes = $this->AppendVariableTypes($payload);

        // Payload-Daten verarbeiten
        foreach ($payloadWithTypes as $key => $value) {

            /**
             * Fatal error: Uncaught TypeError: strpos(): Argument #1 ($haystack) must be of type string, int given in ModulBase.php:1247
             * Stack trace:
             * #0 C:\ProgramData\Symcon\modules\Zigbee2MQTT\libs\ModulBase.php(1247): strpos(0, '_type')
             */
            if ($key === 0) { // Beim Update kommen manchmal 0 als Key, aus welchem Payload auch immer. Muss ja ein [] Payload und kein {} Payload sein.
                continue;
            }
            // Typ-Informationen überspringen
            if (strpos($key, '_type') !== false) {
                continue;
            }

            $this->SendDebug(__FUNCTION__, sprintf('Verarbeite: Key=%s, Value=%s', $key, is_array($value) ? json_encode($value) : (string) $value), 0);

            // Sonderfälle prüfen und verarbeiten
            if ($this->processSpecialVariable($key, $value)) {
                continue;
            }

            // Allgemeine Variablen verarbeiten
            $this->processVariable($key, $value);
        }
    }

    /**
     * getOrRegisterVariable
     *
     * Holt oder registriert eine Variable basierend auf dem Identifikator.
     *
     * Diese Methode prüft, ob eine Variable mit dem angegebenen Identifikator existiert. Wenn nicht,
     * wird die Variable registriert und die ID der neu registrierten Variable zurückgegeben.
     *
     * @param string $ident Der Identifikator der Variable.
     * @param array|null $variableProps Die Eigenschaften der Variable, die registriert werden sollen, falls sie nicht existiert.
     * @param string|null $formattedLabel Das formatierte Label der Variable, falls vorhanden.
     *
     * @return ?int Die ID der Variable oder NULL, wenn die Registrierung fehlschlägt.
     *
     * @see \Zigbee2MQTT\ModulBase::registerVariable()
     * @see \IPSModule::GetBuffer()
     * @see \IPSModule::SendDebug()
     * @see \IPSModule::GetIDForIdent()
     * @see debug_backtrace()
     */
    private function getOrRegisterVariable(string $ident, ?array $variableProps = null, ?string $formattedLabel = null): ?int
    {
        // Während Migration keine Variablen erstellen
        if ($this->BUFFER_PROCESSING_MIGRATION) {
            return null;
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
                return null;
            }
        }
        $this->SendDebug(__FUNCTION__, 'Variable gefunden: ' . $ident . ' (ID: ' . $variableID . ')', 0);
        return $variableID;
    }

    /**
     * processVariable
     *
     * Verarbeitet eine einzelne Variable mit ihrem Wert.
     *
     * Diese Methode wird aufgerufen, um eine einzelne Variable aus dem empfangenen Payload zu verarbeiten.
     * Sie prüft, ob die Variable bekannt ist, registriert sie gegebenenfalls und setzt den Wert.
     *
     * @param string $key Der Schlüssel im empfangenen Payload.
     * @param mixed $value Der Wert, der mit dem Schlüssel verbunden ist.
     *
     * @return void
     *
     * @see \Zigbee2MQTT\ModulBase::SetValue()
     * @see \Zigbee2MQTT\ModulBase::processSpecialVariable()
     * @see \Zigbee2MQTT\ModulBase::getOrRegisterVariable()
     * @see \Zigbee2MQTT\ModulBase::handleColorVariable()
     * @see \Zigbee2MQTT\ModulBase::convertMillivoltToVolt()
     * @see \Zigbee2MQTT\ModulBase::getKnownVariables()
     * @see \IPSModule::SendDebug()
     * @see \IPSModule::GetIDForIdent()
     * @see strtolower()
     * @see is_array()
     * @see strpos()
     */
    private function processVariable(string $key, mixed $value): void
    {
        // Wenn Value ein Array ist und einen 'composite' Key enthält
        if (is_array($value) && isset($value['composite'])) {
            foreach ($value['composite'] as $compositeKey => $compositeValue) {
                $this->processVariable($compositeKey, $compositeValue);
            }
            return;
        }

        $lowerKey = strtolower($key);
        $ident = $key;

        // Prüfe existierende Variable
        $variableID = @$this->GetIDForIdent($ident);
        if ($variableID !== false) {
            $this->SendDebug(__FUNCTION__, 'Existierende Variable gefunden: ' . $ident, 0);
            $this->SetValue($ident, $value);
            return;
        }

        // Bekannte Variablen laden und prüfen
        $knownVariables = $this->getKnownVariables();
        if (!isset($knownVariables[$lowerKey])) {
            $this->SendDebug(__FUNCTION__, 'Variable nicht bekannt: ' . $key, 0);
            return;
        }

        $variableProps = $knownVariables[$lowerKey];

        // Array-Werte verarbeiten
        if (is_array($value)) {
            $this->processArrayValue($ident, $value);
            return;
        }

        // Spezialbehandlungen durchführen
        if ($this->processSpecialCases($key, $value, $lowerKey, $variableProps)) {
            return;
        }

        // Variable registrieren und Wert setzen
        $variableID = $this->getOrRegisterVariable($ident, $variableProps);
        if ($variableID) {
            $this->SetValue($ident, $value);
        }
    }

    private function processArrayValue(string $ident, array $value): void
    {
        if (strpos($ident, 'color') === 0) {
            $this->handleColorVariable($ident, $value);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'Array-Wert für: ' . $ident, 0);
        $this->SendDebug(__FUNCTION__, 'Inhalt: ' . json_encode($value), 0);
    }

    private function processSpecialCases(string $key, mixed &$value, string $lowerKey, array $variableProps): bool
    {
        // Brightness in Lichtgruppen
        foreach (self::$VariableUseStandardProfile as $profile) {
            if (
                $profile['feature'] === $lowerKey &&
                isset($profile['group_type'], $variableProps['group_type']) &&
                $profile['group_type'] === 'light' &&
                $variableProps['group_type'] === 'light'
            ) {

                $this->SendDebug(__FUNCTION__, 'Brightness in Lichtgruppe - StandardProfile', 0);
                return $this->processSpecialVariable($key, $value);
            }
        }

        // Voltage Behandlung
        if ($lowerKey === 'voltage') {
            $this->SendDebug(__FUNCTION__, 'Voltage vor Konvertierung: ' . $value, 0);
            if ($this->processSpecialVariable($key, $value)) {
                return true;
            }
            $value = self::convertMillivoltToVolt($value);
            $this->SendDebug(__FUNCTION__, 'Voltage nach Konvertierung: ' . $value, 0);
        }

        return false;
    }

    /**
     * handleStandardVariable
     *
     * Verarbeitet Standard-Variablenaktionen.
     *
     * Diese Methode wird aufgerufen, wenn eine Aktion für eine Standard-Variable angefordert wird.
     * Sie konvertiert den Wert bei Bedarf und sendet den entsprechenden Set-Befehl.
     *
     * @param string $ident Der Identifikator der Standard-Variable.
     * @param mixed $value Der Wert, der mit der Standard-Variablen-Aktionsanforderung verbunden ist.
     *
     * @return bool Gibt true zurück, wenn die Aktion erfolgreich verarbeitet wurde, andernfalls false.
     *
     * @see \Zigbee2MQTT\ModulBase::getOrRegisterVariable()
     * @see \Zigbee2MQTT\ModulBase::normalizeValueToRange()
     * @see \Zigbee2MQTT\ModulBase::SendSetCommand()
     * @see \IPSModule::SendDebug()
     * @see is_bool()
     */
    private function handleStandardVariable(string $ident, mixed $value): bool
    {
        $variableID = $this->getOrRegisterVariable($ident);
        if (!$variableID) {
            return false;
        }

        // Konvertiere boolesche Werte zu "ON"/"OFF"
        if (is_bool($value)) {
            $value = $value ? 'ON' : 'OFF';
        }
        // light-Brightness wird immer das Profil ~Intensity.100 haben
        if ($ident === 'brightness') {
            // Konvertiere Prozentwert (0-100) in Gerätewert
            $deviceValue = $this->normalizeValueToRange($value, true);
            $payload = ['brightness' => $deviceValue];
            $this->SendSetCommand($payload);
            return true;
        }

        // Erstelle das Payload
        $payload = [$ident => $value];
        $this->SendDebug(__FUNCTION__, 'Sende payload: ' . json_encode($payload), 0);

        // Sende den Set-Befehl
        return $this->SendSetCommand($payload);
    }

    /**
     * handleStateVariable
     *
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
     * Beispiel:
     * ```php
     * // Einfacher ON/OFF State
     * $this->handleStateVariable("state", true); // Sendet ON
     * $this->handleStateVariable("state", false); // Sendet OFF
     * ```
     *
     * @see \Zigbee2MQTT\ModulBase::SendSetCommand()
     * @see \Zigbee2MQTT\ModulBase::SetValueDirect()
     * @see \IPSModule::SendDebug()
     * @see json_encode()
     * @see preg_match()
     * @see is_bool()
     * @see strtoupper()
     */
    private function handleStateVariable(string $ident, mixed $value): bool
    {
        $this->SendDebug(__FUNCTION__, 'State-Handler für: ' . $ident . ' mit Wert: ' . json_encode($value), 0);

        // Prüfe auf Standard-State Pattern und konvertiere zu MQTT-Payload-Key
        if (preg_match(self::STATE_PATTERN['SYMCON'], $ident)) {
            $payload = [$ident => $value ? 'ON' : 'OFF'];
            $this->SendDebug(__FUNCTION__, 'State-Payload wird gesendet: ' . json_encode($payload), 0);
            if (!$this->SendSetCommand($payload)) {
                return false;
            }
            $this->SetValueDirect($ident, $value ? 'ON' : 'OFF');
            return true;
        }

        // Prüfe auf vordefinierte States
        if (isset(static::$stateDefinitions[$ident])) {
            $stateInfo = static::$stateDefinitions[$ident];
            if (isset($stateInfo['values'])) {
                $index = is_bool($value) ? (int) $value : $value;
                if (isset($stateInfo['values'][$index])) {
                    $payload = [$ident => $stateInfo['values'][$index]];
                    $this->SendDebug(__FUNCTION__, 'Vordefinierter State-Payload wird gesendet: ' . json_encode($payload), 0);
                    if (!$this->SendSetCommand($payload)) {
                        return false;
                    }
                    $this->SetValueDirect($ident, $stateInfo['values'][$index]);
                    return true;
                }
            }
        }

        // Überprüfen, ob der Wert in STATE_PATTERN definiert ist
        if (isset(self::STATE_PATTERN[strtoupper($value)])) {
            $adjustedValue = self::STATE_PATTERN[strtoupper($value)];
            $this->SendDebug(__FUNCTION__, 'State-Wert gefunden: ' . $value . ' -> ' . json_encode($adjustedValue), 0);
            $this->SetValueDirect($ident, $adjustedValue);
            return true;
        }

        $this->SendDebug(__FUNCTION__, 'Kein passender State-Handler gefunden', 0);
        return false;
    }

    /**
     * handleColorVariable
     *
     * Verarbeitet Farbvariablenaktionen.
     *
     * Diese Methode wird aufgerufen, wenn eine Aktion für eine Farbvariable angefordert wird.
     * Sie verarbeitet verschiedene Arten von Farbvariablen basierend auf dem Identifikator der Variable.
     *
     * @param string $ident Der Identifikator der Farbvariable.
     * @param mixed $value Der Wert, der mit der Farbvariablen-Aktionsanforderung verbunden ist.
     *
     * @return bool Gibt true zurück, wenn die Aktion erfolgreich verarbeitet wurde, andernfalls false.
     *
     * @see \Zigbee2MQTT\ModulBase::xyToInt()
     * @see \Zigbee2MQTT\ModulBase::SendSetCommand()
     * @see \Zigbee2MQTT\ModulBase::SetValueDirect()
     * @see \Zigbee2MQTT\ModulBase::setColor()
     * @see \Zigbee2MQTT\ModulBase::convertKelvinToMired()
     * @see \IPSModule::SendDebug()
     * @see json_encode()
     * @see is_int()
     * @see is_array()
     * @see sprintf()
     */
    private function handleColorVariable(string $ident, mixed $value): bool
    {
        $handled = match ($ident) {
            'color' => function () use ($value)
            {
                $this->SendDebug(__FUNCTION__, 'Color Value: ' . json_encode($value), 0);
                if (is_int($value)) { //Schaltaktion aus Symcon
                    if ($this->GetValue('color') !== $value) {
                        return $this->setColor($value, 'cie');
                    }
                    return false;
                } elseif (is_array($value)) { //Datenempfang
                    // Prüfen auf x/y Werte im color Array
                    if (isset($value['color']) && isset($value['color']['x']) && isset($value['color']['y'])) {
                        $brightness = $value['brightness'] ?? 254;
                        $this->SendDebug(__FUNCTION__, 'Processing color with brightness: ' . $brightness, 0);

                        // Umrechnung der x und y Werte in einen HEX-Wert mit Helligkeit
                        $hexValue = $this->xyToInt($value['color']['x'], $value['color']['y'], $brightness);
                        $this->SetValueDirect('color', $hexValue);
                    } elseif (isset($value['x']) && isset($value['y'])) {
                        // Direkte x/y Werte
                        $brightness = $value['brightness'] ?? 254;
                        $hexValue = $this->xyToInt($value['x'], $value['y'], $brightness);
                        $this->SetValueDirect('color', $hexValue);
                    }
                    return true;
                }
                $this->SendDebug(__FUNCTION__, 'Ungültiger Wert für color: ' . json_encode($value), 0);
                return false;
            },
            'color_hs' => function () use ($value)
            {
                $this->SendDebug(__FUNCTION__, 'Color HS', 0);
                return $this->setColor($value, 'hs');
            },
            'color_rgb' => function () use ($value)
            {
                $this->SendDebug(__FUNCTION__, 'Color RGB', 0);
                return $this->setColor($value, 'cie', 'color_rgb');
            },
            'color_temp_kelvin' => function () use ($value)
            {
                // Konvertiere Kelvin zu Mired
                $convertedValue = $this->convertKelvinToMired($value);
                $payloadKey = 'color_temp'; // Zigbee2MQTT erwartet immer color_temp als Key
                $payload = [$payloadKey => $convertedValue];

                // Debug Ausgabe
                $this->SendDebug(__FUNCTION__, sprintf('Converting %dK to %d Mired', $value, $convertedValue), 0);

                // Sende Payload an Gerät
                if (!$this->SendSetCommand($payload)) {
                    return false;
                }

                // Aktualisiere auch die Mired-Variable
                $this->SetValueDirect('color_temp', $convertedValue);

                return true;
            },
            'color_temp' => function () use ($value)
            {
                $convertedValue = $this->convertKelvinToMired($value);
                $this->SendDebug(__FUNCTION__, 'Converted Color Temp: ' . $convertedValue, 0);
                $payload = ['color_temp' => $convertedValue];
                return $this->SendSetCommand($payload);
            },
            default => function ()
            {
                return false;
            },
        };

        return $handled();
    }

    /**
     * handlePresetVariable
     *
     * Verarbeitet Preset-Variablenaktionen.
     *
     * Diese Methode wird aufgerufen, wenn eine Aktion für eine Preset-Variable angefordert wird.
     * Sie leitet die Aktion an die Hauptvariable weiter und sendet den entsprechenden Set-Befehl.
     *
     * @param string $ident Der Identifikator der Preset-Variable.
     * @param mixed $value Der Wert, der mit der Preset-Aktionsanforderung verbunden ist.
     *
     * @return bool Gibt true zurück, wenn die Aktion erfolgreich verarbeitet wurde, andernfalls false.
     *
     * @see \Zigbee2MQTT\ModulBase::SetValue()
     * @see \Zigbee2MQTT\ModulBase::SetValueDirect()
     * @see \Zigbee2MQTT\ModulBase::SendSetCommand()
     * @see \Zigbee2MQTT\ModulBase::convertMiredToKelvin()
     * @see \IPSModule::SendDebug()
     * @see str_replace()
     */
    private function handlePresetVariable(string $ident, mixed $value): bool
    {
        // Extrahiere den Identifikator der Hauptvariable
        $mainIdent = str_replace('_presets', '', $ident);
        $this->SendDebug(__FUNCTION__, 'Aktion über presets erfolgt, Weiterleitung zur eigentlichen Variable: ' . $mainIdent, 0);
        $this->SendDebug(__FUNCTION__, 'Aktion über presets erfolgt, Schreibe zur PresetVariable Variable: ' . $ident, 0);

        // Setze den Wert der Hauptvariable
        $this->SetValue($mainIdent, $value);
        $this->SetValue($ident, $value);

        $payload = [$mainIdent => $value];
        if (!$this->SendSetCommand($payload)) {
            return false;
        }

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
     * handleStringVariableNoResponse
     *
     * Verarbeitet String-Variablen, die keine Rückmeldung von Zigbee2MQTT erfordern.
     *
     * Diese Methode wird aufgerufen, wenn eine Aktion für eine String-Variable angefordert wird,
     * die keine Rückmeldung von Zigbee2MQTT erfordert. Sie sendet den entsprechenden Set-Befehl
     * und aktualisiert die Variable direkt, wenn der Set-Befehl erfolgreich gesendet wurde.
     *
     * @param string $ident Der Identifikator der String-Variable.
     * @param string $value Der Wert, der mit der String-Variablen-Aktionsanforderung verbunden ist.
     *
     * @return bool Gibt true zurück wenn der Set-Befehl abgesetzt wurde, sonder false.
     *
     * @see \Zigbee2MQTT\ModulBase::SetValue()
     * @see \Zigbee2MQTT\ModulBase::SendSetCommand()
     * @see \IPSModule::SendDebug()
     */
    private function handleStringVariableNoResponse(string $ident, string $value): bool
    {
        $this->SendDebug(__FUNCTION__, 'Behandlung String ohne Rückmeldung: ' . $ident, 0);
        $payload = [$ident => $value];
        if ($this->SendSetCommand($payload)) {
            $this->SetValue($ident, $value);
            return true;
        }
        return false;
    }

    /**
     * adjustValueByType
     *
     * Passt den Wert basierend auf dem Variablentyp an.
     *
     * Diese Methode konvertiert den übergebenen Wert in den entsprechenden Typ der Variable.
     *
     * @param array $variableObject Ein Array von IPS_GetVariable()
     * @param mixed $value Der Wert, der angepasst werden soll.
     *
     * @return mixed Der angepasste Wert basierend auf dem Variablentyp.
     *
     * @see \IPSModule::SendDebug()
     * @see json_encode()
     * @see is_bool()
     * @see is_string()
     * @see strtoupper()
     */
    private function adjustValueByType(array $variableObject, mixed $value): mixed
    {
        $varType = $variableObject['VariableType'];
        $varID = $variableObject['VariableID'];

        $this->SendDebug(__FUNCTION__, 'Variable ID: ' . $varID . ', Typ: ' . $varType . ', Ursprünglicher Wert: ' . json_encode($value), 0);

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
                $this->SendDebug(__FUNCTION__, 'Konvertiere zu bool: ' . json_encode((bool) $value), 0);
                return (bool) $value;
            case 1:
                $this->SendDebug(__FUNCTION__, 'Konvertiere zu int: ' . (int) $value, 0);
                return (int) $value;
            case 2:
                $this->SendDebug(__FUNCTION__, 'Konvertiere zu float: ' . (float) $value, 0);
                return (float) $value;
            case 3:
                $this->SendDebug(__FUNCTION__, 'Konvertiere zu string: ' . (string) $value, 0);
                return (string) $value;
            default:
                $this->SendDebug(__FUNCTION__, 'Unbekannter Variablentyp für ID ' . $varID . ', Wert: ' . json_encode($value), 0);
                return $value;
        }
    }

    // Farbmanagement

    /**
     * setColor
     *
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
     * @return bool
     *
     * @throws \InvalidArgumentException Wenn der Modus ungültig ist.
     *
     * Beispiel:
     * ```php
     * // Setze eine Farbe im HSL-Modus.
     * $this->setColor(0xFF5733, 'hsl', 'color');
     *
     * // Setze eine Farbe im HSV-Modus.
     * $this->setColor(0x4287f5, 'hsv', 'color');
     * ```
     *
     * @see \Zigbee2MQTT\ModulBase::IntToRGB()
     * @see \Zigbee2MQTT\ModulBase::RGBToXy()
     * @see \Zigbee2MQTT\ModulBase::RGBToHSB()
     * @see \Zigbee2MQTT\ModulBase::RGBToHSL()
     * @see \Zigbee2MQTT\ModulBase::RGBToHSV()
     * @see \Zigbee2MQTT\ModulBase::SendSetCommand()
     * @see \IPSModule::SendDebug()
     * @see json_encode()
     */
    private function setColor(int $color, string $mode, string $Z2MMode = 'color', ?int $TransitionTime = null): bool
    {
        $Payload = match ($mode) {
            'cie' => function () use ($color, $Z2MMode)
            {
                $RGB = $this->IntToRGB($color);
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
            'hs' => function () use ($color, $Z2MMode)
            {
                $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: setColor - Input Color', json_encode($color), 0);

                $RGB = $this->IntToRGB($color);
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
            'hsl' => function () use ($color, $Z2MMode)
            {
                $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: setColor - Input Color', json_encode($color), 0);

                $RGB = $this->IntToRGB($color);
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
            'hsv' => function () use ($color, $Z2MMode)
            {
                $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: setColor - Input Color', json_encode($color), 0);

                $RGB = $this->IntToRGB($color);
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
            default => throw new \InvalidArgumentException('Invalid color mode: ' . $mode),
        };

        $result = $Payload();
        if ($result !== null) {

            if ($result === false) {
                return true; // Wert hat sich nicht geändert
            }
            if ($TransitionTime !== null) {
                $result['transition'] = $TransitionTime;
            }
            return $this->SendSetCommand($result);
        }
        return false;
    }

    // Spezialvariablen & Konvertierung

    /**
     * processSpecialVariable
     *
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
     * Beispiel:
     * ```php
     * // Verarbeitet Farbtemperatur
     * $this->processSpecialVariable("color_temp", 4000);
     *
     * // Verarbeitet RGB-Farbe
     * $this->processSpecialVariable("color", ["r" => 255, "g" => 0, "b" => 0]);
     * ```
     *
     * @see \Zigbee2MQTT\ModulBase::processPayload() Ruft diese Methode auf
     * @see \Zigbee2MQTT\ModulBase::processVariable() Ruft diese Methode auf
     *
     * @see \Zigbee2MQTT\ModulBase::convertLabelToName()
     * @see \Zigbee2MQTT\ModulBase::getOrRegisterVariable()
     * @see \Zigbee2MQTT\ModulBase::adjustSpecialValue()
     * @see \Zigbee2MQTT\ModulBase::SetValueDirect()
     * @see \IPSModule::SendDebug()
     * @see is_array()
     * @see json_encode()
     * @see sprintf()
     * @see gettype()
     */
    private function processSpecialVariable(string $key, mixed $value): bool
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
     * adjustSpecialValue
     *
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
     * Beispiel:
     * ```php
     * // LastSeen konvertieren
     * $this->adjustSpecialValue("last_seen", 1600000000000); // Returns: 1600000000
     *
     * // ColorMode konvertieren
     * $this->adjustSpecialValue("color_mode", "hs"); // Returns: "HS"
     *
     * // Kelvin zu Mired
     * $this->adjustSpecialValue("color_temp_kelvin", 4000); // Returns: "250"
     * ```
     *
     * @see \Zigbee2MQTT\ModulBase::convertKelvinToMired()
     * @see \Zigbee2MQTT\ModulBase::normalizeValueToRange()
     * @see \Zigbee2MQTT\ModulBase::convertMillivoltToVolt()
     * @see \IPSModule::SendDebug()
     * @see is_array()
     * @see json_encode()
     * @see intdiv()
     * @see strtoupper()
     */
    private function adjustSpecialValue(string $ident, mixed $value): mixed
    {
        $debugValue = is_array($value) ? json_encode($value) : $value;
        $this->SendDebug(__FUNCTION__, 'Processing special variable: ' . $ident . ' with value: ' . $debugValue, 0);
        switch ($ident) {
            case 'last_seen':
                // Umrechnung von Millisekunden auf Sekunden
                $adjustedValue = intdiv($value, 1000);
                $this->SendDebug(__FUNCTION__, 'Converted value: ' . $adjustedValue, 0);
                return $adjustedValue;
            case 'color_mode':
                // Konvertierung von 'hs' zu 'HS' und 'xy' zu 'XY'
                $adjustedValue = strtoupper($value);
                $this->SendDebug(__FUNCTION__, 'Converted value: ' . $adjustedValue, 0);
                return $adjustedValue;
            case 'color_temp_kelvin':
                // Umrechnung von Kelvin zu Mired
                return $this->convertKelvinToMired($value);
            case 'brightness':
                // Konvertiere Gerätewert in Prozentwert (0-100)
                $adjustedValue = $this->normalizeValueToRange($value, false);
                $this->SendDebug(__FUNCTION__, 'Converted brightness value: ' . $adjustedValue, 0);
                return $adjustedValue;
            case 'voltage':
                // Konvertiere mV zu V
                $adjustedValue = self::convertMillivoltToVolt($value);
                $this->SendDebug(__FUNCTION__, 'Converted voltage value: ' . $adjustedValue, 0);
                return $adjustedValue;
            default:
                return $value;
        }
    }

    /**
     * convertMillivoltToVolt
     *
     * Konvertiert Millivolt in Volt, wenn der Wert größer als 400 ist.
     *
     * @param float $value Der zu konvertierende Wert in Millivolt.
     * @return float Der konvertierte Wert in Volt.
     */
    private static function convertMillivoltToVolt(float $value): float
    {
        if ($value > 400) { // Werte über 400 sind in mV
            return $value * 0.001; // Umrechnung von mV in V mit Faktor 0.001
        }
        return $value; // Werte <= 400 sind bereits in V
    }

    /**
     * convertLabelToName
     *
     * Konvertiert ein Label in einen formatierten Namen mit Großbuchstaben am Wortanfang
     * und behält bestimmte Abkürzungen in Großbuchstaben. Speichert den konvertierten Namen in einer JSON-Datei.
     *
     * @param string $label Das zu formatierende Label
     * @return string Das formatierte Label
     *
     * @see \Zigbee2MQTT\ModulBase::isValueInLocaleJson()
     * @see \Zigbee2MQTT\ModulBase::addValueToTranslationsJson()
     * @see \IPSModule::SendDebug()
     * @see str_replace()
     * @see str_ireplace()
     * @see strtolower()
     * @see ucwords()
     * @see ucfirst()
     */
    private function convertLabelToName(string $label): string
    {
        // Liste von Abkürzungen die in Großbuchstaben bleiben sollen
        $upperCaseWords = ['HS', 'RGB', 'XY', 'HSV', 'HSL', 'LED'];

        // Ersetze Unterstriche durch Leerzeichen
        $label = str_replace('_', ' ', $label);

        // Konvertiere jeden Wortanfang in Großbuchstaben
        $label = ucwords($label);

        // Ersetze bekannte Abkürzungen durch ihre Großbuchstaben-Version
        foreach ($upperCaseWords as $upperWord) {
            $label = str_ireplace(
                [" $upperWord", ' ' . ucfirst(strtolower($upperWord))],
                " $upperWord",
                $label
            );
        }

        $this->SendDebug(__FUNCTION__, 'Converted Label: ' . $label, 0);

        // Prüfe, ob der Name in der locale.json vorhanden ist
        // Füge den Namen zur translations.json hinzu
        self::isValueInLocaleJson($label);
        return $label;
    }

    // Profilmanagement

    /**
     * registerVariableProfile
     *
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
     *
     * @return string Name des erstellten/vorhandenen Profils
     *                - '~Switch' für Standard-Schalter
     *                - 'Z2M.[property]' für benutzerdefinierte Profile
     *                - Systemprofil-Name wenn verfügbar
     *
     * Beispiel:
     * ```php
     * // Binary Switch
     * $expose = [
     *     'type' => 'binary',
     *     'property' => 'state',
     *     'value_on' => 'ON',
     *     'value_off' => 'OFF'
     * ];
     * $profile = $this->registerVariableProfile($expose);
     * // Ergebnis: '~Switch'
     * ```
     *
     *
     * @see \Zigbee2MQTT\ModulBase::registerStateMappingProfile()
     * @see \Zigbee2MQTT\ModulBase::registerStringProfile()
     * @see \Zigbee2MQTT\ModulBase::getStandardProfile()
     * @see \Zigbee2MQTT\ModulBase::isValidStandardProfile()
     * @see \Zigbee2MQTT\ModulBase::handleProfileType()
     * @see strtolower()
     * @see strtoupper()
     * @see is_string()
     * @see str_replace()
     *
     */
    private function registerVariableProfile(array $expose): string
    {
        $type = $expose['type'] ?? '';
        $property = $expose['property'] ?? $expose['name'];
        $ProfileName = 'Z2M.' . strtolower($property);

        // Entferne das doppelte Präfix, falls vorhanden
        $ProfileName = str_replace('Z2M.Z2M_', 'Z2M.', $ProfileName);

        // State-Mapping prüfen
        if (isset(self::$stateDefinitions[$ProfileName])) {
            $this->registerStateMappingProfile($ProfileName);
            return $ProfileName;
        }

        // Enum-Profil-Logik
        if ($type === 'enum' && isset($expose['values'])) {
            return $this->registerEnumProfile($expose, $ProfileName);
        }

        // Standard-Profil prüfen
        if ($type === 'binary') {
            if (isset($expose['value_on']) && isset($expose['value_off'])) {
                $valueOn = $expose['value_on'];
                $valueOff = $expose['value_off'];

                // Prüfen, ob die Werte Strings sind, bevor strtoupper verwendet wird
                if (
                    ($valueOn === true && $valueOff === false) ||
                    ($valueOn === false && $valueOff === true) ||
                    (is_string($valueOn) && is_string($valueOff) &&
                        strtoupper($valueOn) === 'ON' && strtoupper($valueOff) === 'OFF')
                ) {
                    return '~Switch';
                } else {
                    return $this->registerStringProfile($ProfileName, (string) $valueOn, (string) $valueOff);
                }
            }
            return '~Switch';
        }

        $standardProfile = $this->getStandardProfile($type, $property);
        if (self::isValidStandardProfile($standardProfile)) {
            return $standardProfile;
        }

        // Typ-spezifisches Profil erstellen
        return $this->handleProfileType($type, $expose, $ProfileName);
    }

    /**
     * getStandardProfile
     *
     * Holt das Standardprofil basierend auf Typ und Eigenschaft.
     *
     * Diese Methode sucht in den vordefinierten Standardprofilen (VariableUseStandardProfile)
     * nach einem passenden Profil für die übergebene Kombination aus Typ und Eigenschaft.
     *
     * @param string $type Der Typ des Exposes (z.B. 'binary', 'numeric', 'enum')
     * @param string $property Die Eigenschaft des Exposes (z.B. 'temperature', 'humidity')
     * @param string|null $groupType Optional - Spezifischer Gruppentyp für erweiterte Profilzuordnung
     *
     * @return string Der Name des Standardprofils oder leer, wenn kein Standardprofil definiert ist
     *                     - '~Temperature' für Temperatur-Eigenschaften
     *                     - '~Humidity' für Feuchtigkeits-Eigenschaften
     *                     - '~Battery' für Batterie-Eigenschaften
     *                     - leer wenn kein passendes Profil gefunden wurde
     *
     * Beispiel:
     * ```php
     * // Temperatur-Profil
     * $profile = $this->getStandardProfile('numeric', 'temperature');
     * // Ergebnis: '~Temperature'
     *
     * // Gruppen-spezifisches Profil
     * $profile = $this->getStandardProfile('binary', 'state', 'light');
     * // Ergebnis: '~Switch'
     * ```
     *
     * @see \IPSModule::SendDebug()
     * @see json_encode()
     */
    private function getStandardProfile(string $type, string $property, ?string $groupType = null): string
    {
        $this->SendDebug(__FUNCTION__, "Checking for standard profile with type: $type, property: $property, groupType: $groupType", 0);

        // Überprüfen, ob ein Standardprofil für den Typ und die Eigenschaft definiert ist
        foreach (self::$VariableUseStandardProfile as $entry) {
            $this->SendDebug(__FUNCTION__, 'Checking entry: ' . json_encode($entry), 0);
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
        return '';
    }

    /**
     * getVariableTypeFromProfile
     *
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
     * Beispiel:
     * ```php
     * // Float Beispiel (Temperatur)
     * $type = $this->getVariableTypeFromProfile('numeric', 'temperature', '°C', 0.5);
     * // Ergebnis: 'float'
     *
     * // Integer Beispiel (Helligkeit)
     * $type = $this->getVariableTypeFromProfile('numeric', 'brightness', '%', 1.0);
     * // Ergebnis: 'int'
     * ```
     *
     * @see \IPSModule::SendDebug()
     * @see is_string()
     * @see mb_convert_encoding()
     * @see str_replace()
     * @see in_array()
     * @see fmod()
     */
    private function getVariableTypeFromProfile(string $type, string $feature, $unit = '', float $value_step = 1.0, ?string $groupType = null): string
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
            // Debug der Original-Einheit
            $this->SendDebug(__FUNCTION__, 'Original unit: ' . bin2hex($unit), 0);

            // Verbesserte UTF-8 Behandlung
            $unit = mb_convert_encoding($unit, 'UTF-8', 'AUTO');
            $unitTrimmed = str_replace(' ', '', $unit);

            // Erweiterte Debug-Ausgaben
            $this->SendDebug(__FUNCTION__, 'Unit after UTF-8 conversion: ' . bin2hex($unitTrimmed), 0);
            $this->SendDebug(__FUNCTION__, 'Unit after conversion (readable): ' . $unitTrimmed, 0);
            $this->SendDebug(__FUNCTION__, 'FLOAT_UNITS content: ' . json_encode(self::FLOAT_UNITS), 0);

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
     * isValidStandardProfile
     *
     * Überprüft, ob ein Standardprofil gültig ist.
     *
     * Diese Methode überprüft, ob ein Standardprofil vergeben ist und ob es existiert.
     *
     * @param string $profile Der Name des Standardprofils.
     * @return bool Gibt true zurück, wenn das Standardprofil gültig ist, andernfalls false.
     *
     * @see IPS_VariableProfileExists()
     * @see strpos()
     */
    private static function isValidStandardProfile(string $profile): bool
    {
        // Überprüfen, ob das Profil nicht null und nicht leer ist
        if ($profile === '') {
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
     * handleProfileType
     *
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
     *
     * @see \Zigbee2MQTT\ModulBase::registerBinaryProfile()
     * @see \Zigbee2MQTT\ModulBase::registerEnumProfile()
     * @see \Zigbee2MQTT\ModulBase::registerNumericProfile()
     * @see \Zigbee2MQTT\ModulBase::handleProfileType()
     * @see \Zigbee2MQTT\ModulBase::registerSpecialVariable()
     * @see \IPSModule::SendDebug()
     * @see strtolower()
     * @see json_encode()
     */
    private function handleProfileType(string $type, array $expose, string $ProfileName): string
    {
        $this->SendDebug(__FUNCTION__, 'Processing type: ' . $type . ' for profile: ' . $ProfileName, 0);
        $this->SendDebug(__FUNCTION__, 'Expose data: ' . json_encode($expose), 0);

        switch ($type) {
            case 'binary':
                return $this->registerBinaryProfile($ProfileName);

            case 'enum':
                return $this->registerEnumProfile($expose, $ProfileName);

            case 'numeric':
                $result = $this->registerNumericProfile($expose);
                if (!isset($result['mainProfile'])) {
                    $this->SendDebug(__FUNCTION__, 'Error: No mainProfile returned from registerNumericProfile', 0);
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
     * registerBinaryProfile
     *
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
     * Beispiel:
     * ```php
     * $profile = $this->registerBinaryProfile('Z2M.Switch');
     * // Erstellt ein Profil mit den Werten:
     * // false -> "Aus" (rot)
     * // true  -> "An"  (grün)
     * ```
     *
     * @see \Zigbee2MQTT\ModulBase::RegisterProfileBooleanEx()
     * @see \IPSModule::SendDebug()
     */
    private function registerBinaryProfile(string $ProfileName): string
    {
        // Registriere das Boolean-Profil mit ON/OFF Werten
        $this->RegisterProfileBooleanEx(
            $ProfileName,
            'Power',  // Icon
            '',       // Prefix
            '',       // Suffix
            [
                [false, 'Off', '', 0xFF0000],  // Rot für Aus
                [true, 'On', '', 0x00FF00]     // Grün für An
            ]
        );

        $this->SendDebug(__FUNCTION__, 'Binary-Profil erstellt: ' . $ProfileName, 0);
        return $ProfileName;
    }

    /**
     * registerEnumProfile
     *
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
     * Beispiel:
     * ```php
     * $expose = [
     *     'values' => ['auto', 'manual', 'boost']
     * ];
     * $profile = $this->registerEnumProfile($expose, 'Z2M.Mode');
     * // Ergebnis: Z2M.Mode.a1b2c3d4
     * ```
     *
     * @note Die Werte werden automatisch:
     *       - Sortiert für konsistente Hash-Generierung
     *       - In lesbare Form konvertiert (z.B. manual -> Manual)
     *       - In translations.json hinzugefügt falls nicht vorhanden
     *
     * @see \Zigbee2MQTT\ModulBase::isValueInLocaleJson()
     * @see \Zigbee2MQTT\ModulBase::addValueToTranslationsJson()
     * @see \Zigbee2MQTT\ModulBase::RegisterProfileStringEx()
     * @see \IPSModule::SendDebug()
     * @see sort()
     * @see implode()
     * @see dechex()
     * @see crc32()
     * @see ucwords()
     * @see str_replace()
     * @see json_encode()
     */
    private function registerEnumProfile(array $expose, string $ProfileName): string
    {
        if (!isset($expose['values'])) {
            $this->SendDebug(__FUNCTION__, 'Keine Werte für Enum-Profil gefunden', 0);
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
            // Prüfe, ob der Wert in der locale.json vorhanden ist
            // Füge den Wert zur translations.json hinzu
            self::isValueInLocaleJson($readableValue);
            $profileValues[] = [(string) $value, $readableValue, '', 0x00FF00];
        }

        // Registriere das Profil
        $this->RegisterProfileStringEx(
            $ProfileName,
            'Menu',
            '',
            '',
            $profileValues
        );

        $this->SendDebug(__FUNCTION__, 'Enum-Profil erstellt: ' . $ProfileName . ' mit Werten: ' . json_encode($profileValues), 0);
        return $ProfileName;
    }

    /**
     * registerNumericProfile
     *
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
     * Beispiel:
     * ```php
     * $expose = [
     *     'type' => 'numeric',
     *     'property' => 'temperature',
     *     'unit' => '°C',
     *     'value_min' => 0,
     *     'value_max' => 40,
     *     'value_step' => 0.5
     * ];
     * $result = $this->registerNumericProfile($expose);
     * ```
     *
     * @see \Zigbee2MQTT\ModulBase::getVariableTypeFromProfile()
     * @see \Zigbee2MQTT\ModulBase::getStandardProfile()
     * @see \Zigbee2MQTT\ModulBase::isValidStandardProfile()
     * @see \Zigbee2MQTT\ModulBase::getFullRangeProfileName()
     * @see strtolower()
     * @see strtoupper()
     */
    private function registerNumericProfile(array $expose): array
    {
        // Frühe Typ-Bestimmung
        $type = $expose['type'] ?? '';
        $feature = $expose['property'] ?? '';
        $unit = isset($expose['unit']) && is_string($expose['unit']) ? $expose['unit'] : '';
        $value_step = isset($expose['value_step']) ? (float) $expose['value_step'] : 1.0;

        // Bestimme Variablentyp
        $variableType = $this->getVariableTypeFromProfile($type, $feature, $unit, $value_step);
        $this->SendDebug(__FUNCTION__, 'Initial Variable Type: ' . $variableType, 0);

        // Standardprofil-Prüfung
        $standardProfile = $this->getStandardProfile($type, $feature);
        if ($standardProfile !== '') {
            if (self::isValidStandardProfile($standardProfile)) {
                return ['mainProfile' => $standardProfile, 'presetProfile' => null];
            }
            return ['mainProfile' => $standardProfile, 'presetProfile' => null];
        }

        // Eigenes Profil erstellen
        $fullRangeProfileName = self::getFullRangeProfileName($expose);
        $min = $expose['value_min'] ?? 0;
        $max = $expose['value_max'] ?? 0;
        $step = $expose['value_step'] ?? 1.0;

        // Verbesserte UTF8-Decodierung für unit
        $unitWithSpace = '';
        if ($unit !== '') {
            // Einfache UTF8-Konvertierung für korrekte Darstellung von Sonderzeichen
            $unitWithSpace = ' ' . mb_convert_encoding($unit, 'ISO-8859-1', 'UTF-8');
        }

        // Profil entsprechend Variablentyp erstellen
        if ($variableType === 'float') {
            $this->RegisterProfileFloat($fullRangeProfileName, '', '', $unitWithSpace, (float) $min, (float) $max, (float) $step, 2);
            $this->SendDebug(__FUNCTION__, 'Created Float Profile: ' . $fullRangeProfileName, 0);
        } else {
            $this->RegisterProfileInteger($fullRangeProfileName, '', '', $unitWithSpace, (int) $min, (int) $max, (float) $step);
            $this->SendDebug(__FUNCTION__, 'Created Integer Profile: ' . $fullRangeProfileName, 0);
        }

        // Preset-Handling
        $presetProfileName = null;
        if (isset($expose['presets']) && !empty($expose['presets'])) {
            $formattedLabel = $this->convertLabelToName($feature);
            $presetProfileName = $this->registerPresetProfile($expose['presets'], $formattedLabel, $variableType, $expose);
        }

        return ['mainProfile' => $fullRangeProfileName, 'presetProfile' => $presetProfileName];
    }

    /**
     * registerStringProfile
     *
     * Erstellt ein benutzerdefiniertes Stringprofil für Variablen.
     *
     * Diese Methode erstellt ein Profil für String-Variablen mit benutzerdefinierten Wertenfür On/Off
     *
     * @param string $ProfileName Der eindeutige Name für das zu erstellende Profil (z.B. 'Z2M.CustomString')
     * @param string $valueOn
     * @param string $valueOff
     *
     * @return string Der Name des erstellten Profils
     *
     * @see \Zigbee2MQTT\ModulBase::RegisterProfileStringEx()
     * @see \IPSModule::SendDebug()
     * @see json_encode()
     */
    private function registerStringProfile(string $ProfileName, string $valueOn, string $valueOff): string
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

        $this->SendDebug(__FUNCTION__, 'Custom String-Profil erstellt: ' . $ProfileName . ' mit Werten: ' . json_encode($profileValues), 0);
        return $ProfileName;
    }

    /**
     * registerPresetProfile
     *
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
     *
     * @see \Zigbee2MQTT\ModulBase::RegisterProfileFloatEx()
     * @see \Zigbee2MQTT\ModulBase::RegisterProfileIntegerEx()
     * @see \IPSModule::LogMessage()
     * @see \IPSModule::Translate()
     * @see str_replace()
     * @see sprintf()
     * @see ucwords()
     */
    private function registerPresetProfile(array $presets, string $label, string $variableType, array $feature): string
    {
        // Profilname ohne Leerzeichen erstellen und Min- und Max-Werte hinzufügen
        $profileName = 'Z2M.' . str_replace(' ', '_', $label);
        $valueMin = $feature['value_min'] ?? null;
        $valueMax = $feature['value_max'] ?? null;

        if ($valueMin !== null && $valueMax !== null) {
            $profileName .= '_' . $valueMin . '_' . $valueMax;
        }

        $profileName .= '_Presets';

        // Füge die Presets zum Profil hinzu
        $associations = [];
        foreach ($presets as $preset) {
            // Preset-Wert an den Variablentyp anpassen
            $presetValue = ($variableType === 'float') ? (float) $preset['value'] : (int) $preset['value'];
            $presetName = $this->Translate(ucwords(str_replace('_', ' ', $preset['name'])));
            $this->SendDebug(__FUNCTION__, sprintf('Adding preset: %s with value %s', $presetName, $presetValue), 0);
            $associations[] = [
                $presetValue,
                $presetName,
                '',
                -1
            ];

        }

        // Neues Profil anlegen
        if ($variableType === 'float') {
            if (!$this->RegisterProfileFloatEx($profileName, '', '', '', $associations)) {
                $this->LogMessage(sprintf('%s: Could not create float profile %s', __FUNCTION__, $profileName), KL_DEBUG);
            }
        } else {
            if (!$this->RegisterProfileIntegerEx($profileName, '', '', '', $associations)) {
                $this->LogMessage(sprintf('%s: Could not create integer profile %s', __FUNCTION__, $profileName), KL_DEBUG);
            }
        }

        return $profileName;
    }

    /**
     * checkAndCreateJsonFile
     *
     * Prüft und erstellt eine JSON-Datei für die Zigbee-Geräteinformationen.
     *
     * Diese Methode führt folgende Schritte aus:
     * 1. Prüft ob das MQTT-Topic gesetzt ist
     * 2. Überprüft das Vorhandensein der JSON-Datei im Zigbee2MQTTExposes Verzeichnis
     * 3. Überprüft auf aktiven IO-Parent
     * 4. Ruft UpdateDeviceInfo auf um Geräteinformationen zu aktualisieren
     *
     * @return void
     *
     * @see \Zigbee2MQTT\ModulBase::UpdateDeviceInfo()
     * @see \IPSModule::ReadPropertyString()
     * @see \IPSModule::SendDebug()
     * @see \IPSModule::HasActiveParent()
     * @see IPS_GetKernelDir()
     * @see IPS_GetKernelRunlevel()
     * @see file_exists()
     *
     */
    private function checkAndCreateJsonFile(): void
    {
        $mqttTopic = $this->ReadPropertyString(self::MQTT_TOPIC);

        // Erst prüfen ob MQTTTopic gesetzt ist
        if (empty($mqttTopic)) {
            $this->SendDebug(__FUNCTION__, 'MQTTTopic nicht gesetzt, überspringe JSON Prüfung', 0);
            return;
        }

        $jsonFile = IPS_GetKernelDir() . self::EXPOSES_DIRECTORY . DIRECTORY_SEPARATOR . $this->InstanceID . '.json';

        // Prüfe ob JSON existiert
        if (file_exists($jsonFile)) {
            return;
        }
        $this->SendDebug(__FUNCTION__, 'JSON-Datei nicht gefunden für Instance: ' . $this->InstanceID, 0);

        // Nur fortfahren wenn Parent aktiv
        if (!$this->HasActiveParent()) {
            $this->SendDebug(__FUNCTION__, 'Parent nicht aktiv, überspringe UpdateDeviceInfo', 0);
            return;
        }

        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        $this->SendDebug(__FUNCTION__, 'Starte UpdateDeviceInfo für Topic: ' . $mqttTopic, 0);
        if (!$this->UpdateDeviceInfo()) {
            $this->SendDebug(__FUNCTION__, 'UpdateDeviceInfo fehlgeschlagen', 0);
        }

    }

    /**
     * getKnownVariables
     *
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
     * @return array Ein assoziatives Array mit bekannten Variablen, wobei der Key der normalisierte Property-Name ist
     *               und der Value die komplette Feature-Definition enthält.
     *               Format: ['property_name' => ['property' => 'name', ...]]
     *               Leeres Array wenn keine Variablen gefunden wurden.
     *
     * @see \Zigbee2MQTT\ModulBase::registerVariable() Verwendet die zurückgegebenen Variablen zur Registrierung, über
     * @see \Zigbee2MQTT\ModulBase::processVariable()
     * @see \IPSModule::SendDebug()
     * @see IPS_GetKernelDir()
     * @see file_exists()
     * @see file_get_contents()
     * @see json_decode()
     * @see json_encode()
     * @see array_map()
     * @see array_merge()
     * @see array_filter()
     * @see trim()
     * @see strtolower()
     */
    private function getKnownVariables(): array
    {
        $jsonFile = IPS_GetKernelDir() . self::EXPOSES_DIRECTORY . DIRECTORY_SEPARATOR . $this->InstanceID . '.json';

        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__, 'Verarbeite Datei: ' . $jsonFile, 0);
        if (!file_exists($jsonFile)) {
            $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__, 'JSON-Datei nicht gefunden: ' . $jsonFile, 0);
            if (!isset($data['exposes'])) {
                return [];
            }
        }

        $jsonData = @file_get_contents($jsonFile);
        if (!$jsonData) {
            return [];
        }

        $data = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['exposes'])) {
            $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__, 'Fehler beim Dekodieren der JSON-Datei oder fehlende "exposes" in Datei: ' . $jsonFile . '. Fehler: ' . json_last_error_msg(), 0);
        }

        if (!isset($data['exposes'])) {
            return [];
        }

        $features = array_map(function ($expose)
        {
            return isset($expose['features']) ? $expose['features'] : [$expose];
        }, $data['exposes']);

        $features = array_merge(...$features);

        $filteredFeatures = array_filter($features, function ($feature)
        {
            return isset($feature['property']);
        });

        $knownVariables = [];
        foreach ($filteredFeatures as $feature) {
            $variableName = trim(strtolower($feature['property']));
            $knownVariables[$variableName] = $feature;
        }

        $this->SendDebug(__FUNCTION__ . ' Known Variables Array:', json_encode($knownVariables), 0);

        return $knownVariables;
    }

    /**
     * isValueInLocaleJson
     *
     * Prüft, ob ein Wert in der locale.json vorhanden ist.
     *
     * @param string $value Der zu prüfende Wert.
     * @return bool Gibt true zurück, wenn der Wert in der locale.json vorhanden ist, andernfalls false.
     *
     *@see file_exists()
     *@see strtoupper()
     *@see substr()
     *@see json_decode()
     */
    private static function isValueInLocaleJson(string $Text): bool
    {
        $translation = json_decode(file_get_contents(__DIR__ . '/locale_z2m.json'), true);
        $language = IPS_GetSystemLanguage();
        $code = explode('_', $language)[0];
        if (isset($translation['translations'])) {
            if (isset($translation['translations'][$language])) {
                if (isset($translation['translations'][$language][$Text])) {
                    return true;
                }
            } elseif (isset($translation['translations'][$code])) {
                if (isset($translation['translations'][$code][$Text])) {
                    return true;
                }
            }
        }
        self::addValueToTranslationsJson($Text);
        return false;
    }

    /**
     * addValueToTranslationsJson
     *
     * Fügt einen Wert zur translations.json hinzu, wenn er noch nicht vorhanden ist.
     * Gibt eine Liste an Begriffen, die noch in der locale.json ergänzt werden müssen.
     *
     * @param string $value Der hinzuzufügende Wert.
     * @return void
     *
     * @see file_exists()
     * @see file_get_contents()
     * @see json_decode()
     * @see json_encode()
     * @see in_array()
     * @see file_put_contents()
     */
    private static function addValueToTranslationsJson(string $value): void
    {
        $jsonFile = IPS_GetKernelDir() . self::EXPOSES_DIRECTORY . DIRECTORY_SEPARATOR . 'translations.json';
        // Lade bestehende Übersetzungen
        $translations = [];
        if (file_exists($jsonFile)) {
            $translations = json_decode(file_get_contents($jsonFile), true);
        }

        // Füge den neuen Begriff hinzu, wenn er noch nicht existiert
        if (!in_array($value, $translations)) {
            $translations[] = $value;
            file_put_contents($jsonFile, json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * registerVariable
     *
     * Registriert eine Variable basierend auf den Feature-Informationen.
     * Unterstützt sowohl einzelne als auch zusammengesetzte (composite) Variablen.
     * Bei composite-Variablen werden Sub-Features rekursiv registriert.
     * Verarbeitet automatisch:
     * - Farbtemperatur (color_temp) mit zusätzlicher Kelvin-Variable
     * - Composite-Variablen mit Sub-Features
     * - Preset-Variablen
     * - Access-Flags für schreibbare Variablen
     *
     * @param array|string $feature Feature-Information oder Feature-ID
     *                             Bei array werden zusätzliche Eigenschaften wie 'type', 'property', 'unit' erwartet
     *                             Bei composite werden 'features' als Sub-Feature-Array erwartet
     *                             Optional: 'presets', 'access', 'color_mode'
     * @param string|null $exposeType Optionaler Expose-Typ zur Überschreibung des Feature-Typs
     *
     * @return void
     *
     * @throws Exception Wenn ungültige Feature-Informationen übergeben werden
     *
     * @see \Zigbee2MQTT\ModulBase::getStateConfiguration()
     * @see \Zigbee2MQTT\ModulBase::convertLabelToName()
     * @see \Zigbee2MQTT\ModulBase::registerSpecialVariable()
     * @see \Zigbee2MQTT\ModulBase::getVariableTypeFromProfile()
     * @see \Zigbee2MQTT\ModulBase::getStandardProfile()
     * @see \Zigbee2MQTT\ModulBase::registerVariableProfile()
     * @see \Zigbee2MQTT\ModulBase::registerColorVariable()
     * @see \Zigbee2MQTT\ModulBase::registerPresetVariables()
     * @see \IPSModule::RegisterVariableBoolean()
     * @see \IPSModule::RegisterVariableInteger()
     * @see \IPSModule::RegisterVariableFloat()
     * @see \IPSModule::RegisterVariableString()
     * @see \IPSModule::Translate()
     * @see \IPSModule::GetBuffer()
     * @see \IPSModule::SendDebug()
     * @see \IPSModule::EnableAction()
     * @see \IPSModule::GetIDForIdent()
     * @see is_array()
     * @see json_encode()
     * @see ucfirst()
     * @see str_replace()
     */
    private function registerVariable(mixed $feature, ?string $exposeType = null): void
    {
        // Während Migration keine Variablen erstellen
        if ($this->BUFFER_PROCESSING_MIGRATION) {
            return;
        }

        $featureId = is_array($feature) ? $feature['property'] : $feature;

        // Frühe Validierung der Property
        if (empty($featureId)) {
            $this->SendDebug(__FUNCTION__, 'Error: Empty property/identifier provided', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__ . ' Registriere Variable für Property: ', $featureId, 0);

        // Übergebe das komplette Feature-Array für Access-Check
        $stateConfig = $this->getStateConfiguration($featureId, is_array($feature) ? $feature : null);
        if ($stateConfig !== null) {
            $formattedLabel = $this->convertLabelToName($featureId);

            // Registriere Variable basierend auf dataType
            switch ($stateConfig['dataType']) {
                case VARIABLETYPE_BOOLEAN:
                    $this->RegisterVariableBoolean(
                        $stateConfig['ident'],
                        $this->Translate($formattedLabel),
                        $stateConfig['profile']
                    );
                    break;
                case VARIABLETYPE_STRING:
                    $this->RegisterVariableString(
                        $stateConfig['ident'],
                        $this->Translate($formattedLabel),
                        $stateConfig['profile']
                    );
                    break;
                default:
                    $this->SendDebug(__FUNCTION__, 'Unsupported state dataType: ' . $stateConfig['dataType'], 0);
                    return;
            }

            if (isset($stateConfig['enableAction']) && $stateConfig['enableAction']) {
                $this->EnableAction($stateConfig['ident']);
                $this->SendDebug(__FUNCTION__, 'Enabled action for ' . $featureId . ' (writable state)', 0);
            }
            return;
        }

        // Überprüfung auf spezielle Fälle
        if (isset(self::$specialVariables[$feature['property']])) {
            $this->registerSpecialVariable($feature);
            return;
        }

        // Setze den Typ auf den übergebenen Expose-Typ, falls vorhanden
        if ($exposeType !== null) {
            $feature['type'] = $exposeType;
        }

        // Berücksichtige den Gruppentyp, falls vorhanden, ohne den ursprünglichen Typ zu überschreiben
        $groupType = $feature['group_type'] ?? null;

        $this->SendDebug(__FUNCTION__ . ' :: Registering Feature', json_encode($feature), 0);

        $type = $feature['type'];
        $property = $featureId; // Bereits validiert
        $unit = $feature['unit'] ?? '';
        $ident = $property;     // Bereits validiert
        $label = ucfirst(str_replace('_', ' ', $property));
        $step = isset($feature['step']) ? (float) $feature['step'] : 1.0;

        // Bestimmen des Variablentyps basierend auf Typ, Feature und Einheit
        $variableType = $this->getVariableTypeFromProfile($type, $property, $unit, $step, $groupType);

        // Überprüfen, ob ein Standardprofil verwendet werden soll
        $profileName = $this->getStandardProfile($type, $property, $groupType); /** @todo getStandardProfile wird aber unten in registerVariableProfile auch aufgerufen */

        // Profil vor der Variablenerstellung erstellen, falls kein Standardprofil verwendet wird
        if ($profileName === '') {
            $profileName = $this->registerVariableProfile($feature);
        }

        // Registrierung der Variable basierend auf dem Variablentyp

        switch ($variableType) {
            case 'bool':
                $this->SendDebug(__FUNCTION__, 'Registering Boolean Variable: ' . $ident, 0);
                $this->RegisterVariableBoolean($ident, $this->Translate($this->convertLabelToName($label)), $profileName);
                break;
            case 'int':
                $this->SendDebug(__FUNCTION__, 'Registering Integer Variable: ' . $ident, 0);
                $this->RegisterVariableInteger($ident, $this->Translate($this->convertLabelToName($label)), $profileName);
                break;
            case 'float':
                $this->SendDebug(__FUNCTION__, 'Registering Float Variable: ' . $ident, 0);
                $this->RegisterVariableFloat($ident, $this->Translate($this->convertLabelToName($label)), $profileName);
                break;
            case 'string':
            case 'text':
                $this->SendDebug(__FUNCTION__, 'Registering String Variable: ' . $ident, 0);
                $this->RegisterVariableString($ident, $this->Translate($this->convertLabelToName($label)), $profileName);
                break;
                // Zusätzliche Registrierung für 'composite' Farb-Variablen
            case 'composite':
                $this->SendDebug(__FUNCTION__, 'Registering Composite Variable: ' . $ident, 0);

                // Bestehende Color-Variable Logik beibehalten
                if (isset($feature['color_mode'])) {
                    $this->registerColorVariable($feature);
                    return;
                }

                // Neue Feature-Verarbeitung
                if (isset($feature['features'])) {
                    foreach ($feature['features'] as $subFeature) {
                        // Bilde Sub-Properties
                        $subFeature['property'] = $property . '_' . $subFeature['property'];
                        // Rekursiver Aufruf mit einzelnem Feature
                        /** @todo  aktuell deaktiviert */
                        //$this->registerVariable($subFeature, $exposeType);
                    }
                }
                return;

            default:
                $this->SendDebug(__FUNCTION__, 'Unsupported variable type: ' . $variableType, 0);
                return;
        }

        if (isset($feature['access']) && ($feature['access'] & 0b010) != 0) {
            $this->EnableAction($ident);
            $this->SendDebug(__FUNCTION__, 'Set EnableAction for ident: ' . $ident . ' to: true', 0);
        }
        // Zusätzliche Registrierung der color_temp_kelvin Variable, wenn color_temp registriert wird
        if ($ident === 'color_temp') {
            $kelvinIdent = $ident . '_kelvin';
            $this->RegisterVariableInteger($kelvinIdent, $this->Translate('Color Temperature Kelvin'), '~TWColor');
            $variableId = $this->GetIDForIdent($kelvinIdent);
            $this->EnableAction($kelvinIdent);

        }
        // Preset-Verarbeitung nach der normalen Variablenregistrierung
        if (isset($feature['presets']) && !empty($feature['presets'])) {
            $variableType = $this->getVariableTypeFromProfile($type, $property, $unit, $step, $groupType);
            $this->registerPresetVariables($feature['presets'], $feature['property'], $variableType, $feature);
            $this->SendDebug(__FUNCTION__, 'Registered presets for: ' . $feature['property'], 0);
        }
        return;
    }

    /**
     * registerColorVariable
     *
     * Registriert Farbvariablen für verschiedene Farbmodelle.
     *
     * Diese Methode erstellt und registriert spezielle Variablen für die Farbsteuerung
     * von Zigbee-Geräten. Unterstützt werden die Farbmodelle:
     * - XY-Farbraum (color_xy)
     * - HSV-Farbraum (color_hs)
     * - RGB-Farbraum (color_rgb)
     *
     * @param array $feature Array mit Eigenschaften des Features:
     *                       - 'name': Name des Farbmodells ('color_xy', 'color_hs', 'color_rgb')
     * @return void
     *
     * @see \Zigbee2MQTT\ModulBase::convertLabelToName()
     * @see \IPSModule::GetBuffer()
     * @see \IPSModule::SendDebug()
     * @see \IPSModule::RegisterVariableInteger()
     * @see \IPSModule::EnableAction()
     * @see \IPSModule::Translate()
     * @see debug_backtrace()
     */
    private function registerColorVariable(array $feature): void
    {
        // Während Migration keine Variablen erstellen
        if ($this->BUFFER_PROCESSING_MIGRATION) {
            return;
        }

        switch ($feature['name']) {
            case 'color_xy':
                $this->RegisterVariableInteger('color', $this->Translate($this->convertLabelToName('color')), '~HexColor');
                $this->EnableAction('color');
                $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Creating composite color_xy', 'color', 0);
                break;
            case 'color_hs':
                $this->RegisterVariableInteger('color_hs', $this->Translate($this->convertLabelToName('color_hs')), '~HexColor');
                $this->EnableAction('color_hs');
                $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Creating composite color_hs', 'color_hs', 0);
                break;
            case 'color_rgb':
                $this->RegisterVariableInteger('color_rgb', $this->Translate($this->convertLabelToName('color_rgb')), '~HexColor');
                $this->EnableAction('color_rgb');
                $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Creating composite color_rgb', 'color_rgb', 0);
                break;
            default:
                $this->SendDebug(__FUNCTION__ . ' :: Line ' . __LINE__ . ' :: Unhandled composite type', $feature['name'], 0);
                break;
        }
    }

    /**
     * registerPresetVariables
     *
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
     * Beispiel:
     * ```php
     * $presets = [
     *     ['name' => 'low', 'value' => 20],
     *     ['name' => 'medium', 'value' => 50],
     *     ['name' => 'high', 'value' => 100]
     * ];
     * $this->registerPresetVariables($presets, 'Brightness', 'int', ['property' => 'brightness', 'name' => 'Brightness']);
     * ```
     *
     * @see \Zigbee2MQTT\ModulBase::registerPresetProfile()
     * @see \Zigbee2MQTT\ModulBase::convertLabelToName()
     * @see \IPSModule::GetBuffer()
     * @see \IPSModule::SendDebug()
     * @see \IPSModule::Translate()
     * @see \IPSModule::GetIDForIdent()
     * @see \IPSModule::RegisterVariableFloat()
     * @see \IPSModule::RegisterVariableInteger()
     * @see \IPSModule::EnableAction()
     */
    private function registerPresetVariables(array $presets, string $label, string $variableType, array $feature): void
    {
        // Während Migration keine Variablen erstellen
        if ($this->BUFFER_PROCESSING_MIGRATION) {
            return;
        }

        $this->SendDebug(__FUNCTION__, 'Registering preset variables for: ' . $label, 0);
        $profileName = $this->registerPresetProfile($presets, $label, $variableType, $feature);

        // Variable registrieren
        $ident = ($feature['property']) . '_presets';
        $this->SendDebug(__FUNCTION__, 'Preset ident: ' . $ident, 0);
        $label = $feature['name'] . ' Presets';
        $formattedLabel = $this->convertLabelToName($label);

        // Variable erstellen/aktualisieren
        if ($variableType === 'float') {
            $this->RegisterVariableFloat($ident, $this->Translate($formattedLabel), $profileName);
        } else {
            $this->RegisterVariableInteger($ident, $this->Translate($formattedLabel), $profileName);
        }
        $this->EnableAction($ident);
    }

    /**
     * registerSpecialVariable
     *
     * Registriert spezielle Variablen.
     *
     * @param array $feature Feature-Eigenschaften
     *
     * @return void
     *
     * @see \Zigbee2MQTT\ModulBase::adjustSpecialValue()
     * @see \Zigbee2MQTT\ModulBase::convertLabelToName()
     * @see \Zigbee2MQTT\ModulBase::SetValue()
     * @see \IPSModule::GetBuffer()
     * @see \IPSModule::SendDebug()
     * @see \IPSModule::RegisterVariableFloat()
     * @see \IPSModule::RegisterVariableInteger()
     * @see \IPSModule::RegisterVariableString()
     * @see \IPSModule::RegisterVariableBoolean()
     * @see \IPSModule::Translate()
     * @see \IPSModule::EnableAction()
     * @see sprintf()
     * @see json_encode()
     */
    private function registerSpecialVariable($feature): void
    {
        // Während Migration keine Variablen erstellen
        if ($this->BUFFER_PROCESSING_MIGRATION) {
            return;
        }

        $ident = $feature['property'];
        $this->SendDebug(__FUNCTION__, sprintf('Checking special case for %s: %s', $ident, json_encode($feature)), 0);

        if (!isset(self::$specialVariables[$ident])) {
            return;
        }

        $varDef = self::$specialVariables[$ident];
        $formattedLabel = $this->convertLabelToName($ident);

        // Wert anpassen wenn nötig
        if (isset($feature['value'])) {
            $value = $this->adjustSpecialValue($ident, $feature['value']);
        }

        switch ($varDef['type']) {
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
        return;
    }

    /**
     * getStateConfiguration
     *
     * Prüft und liefert die Konfiguration für State-basierte Features.
     *
     * Diese Methode analysiert ein Feature und bestimmt, ob es sich um ein State-Feature handelt.
     * Sie prüft drei Szenarien:
     * 1. Vordefinierte States aus stateDefinitions
     * 2. Enum-Typ States (z.B. "state" mit definierten Werten)
     * 3. Standard State-Pattern als Boolean (z.B. "state", "state_left")
     *
     * Bei Enum-States wird automatisch ein eindeutiges Profil erstellt:
     * - Profilname: Z2M.[property].[hash]
     * - Hash basiert auf den Enum-Werten
     * - Enthält alle definierten Enum-Werte mit Icons
     *
     * Die zurückgegebene Konfiguration enthält:
     * - type: Typ des States (z.B. 'switch', 'enum')
     * - dataType: IPS Variablentyp (z.B. VARIABLETYPE_BOOLEAN, VARIABLETYPE_STRING)
     * - values: Mögliche Zustände (z.B. ['ON', 'OFF'] oder ['OPEN', 'CLOSE', 'STOP'])
     * - profile: Zu verwendenes IPS-Profil (z.B. '~Switch' oder 'Z2M.state.hash')
     * - enableAction: Ob Aktionen erlaubt sind (basierend auf access)
     * - ident: Normalisierter Identifikator
     *
     * @param string $featureId Feature-Identifikator (z.B. 'state', 'state_left')
     * @param array|null $feature Optionales Feature-Array mit weiteren Eigenschaften:
     *                           - access: Zugriffsrechte (0b010 für Schreibzugriff)
     *                           - type: Datentyp ('enum', 'binary')
     *                           - values: Array möglicher Enum-Werte
     *
     * @return array|null Array mit State-Konfiguration oder null wenn kein State-Feature
     *
     * Beispiel:
     * ```php
     * // Standard boolean state
     * $config = $this->getStateConfiguration('state');
     * // Ergebnis: ['type' => 'switch', 'dataType' => VARIABLETYPE_BOOLEAN, 'profile' => '~Switch', ...]
     *
     * // Enum state mit Profilerstellung
     * $config = $this->getStateConfiguration('state', [
     *     'type' => 'enum',
     *     'values' => ['OPEN', 'CLOSE', 'STOP']
     * ]);
     * // Ergebnis: ['type' => 'enum', 'dataType' => VARIABLETYPE_STRING, 'profile' => 'Z2M.state.hash', ...]
     *
     * // Vordefinierter state
     * $config = $this->getStateConfiguration('valve_state');
     * // Ergebnis: Konfiguration aus stateDefinitions
     * ```
     *
     * @see \IPSModule::SendDebug()
     * @see preg_match()
     */
    private function getStateConfiguration(string $featureId, ?array $feature = null): ?array
    {
        // Basis state-Pattern
        $statePattern = '/^state(?:_[a-z0-9]+)?$/i';

        if (preg_match($statePattern, $featureId)) {
            $this->SendDebug(__FUNCTION__, 'State-Konfiguration für: ' . $featureId, 0);

            // Prüfe ZUERST auf vordefinierte States
            if (isset(static::$stateDefinitions[$featureId])) {
                return static::$stateDefinitions[$featureId];
            }

            // Dann auf enum type
            if (isset($feature['type']) && $feature['type'] === 'enum' && isset($feature['values'])) {

                // Profil-Werte abholen
                $enumFeature = [
                    'type'     => 'enum',
                    'property' => $featureId,
                    'values'   => $feature['values']
                ];

                // Profil anlegen
                $profileName = $this->registerEnumProfile($enumFeature, 'Z2M.' . $featureId);

                // Daten zur Variavblenregistrierung zurückgeben
                return [
                    'type'         => 'enum',
                    'dataType'     => VARIABLETYPE_STRING,
                    'values'       => $feature['values'],
                    'profile'      => $profileName,
                    'enableAction' => (isset($feature['access']) && ($feature['access'] & 0b010) != 0),
                    'ident'        => $featureId
                ];
            }

            // Nur wenn kein enum type und kein vordefinierter state, dann boolean
            return [
                'type'         => 'switch',
                'dataType'     => VARIABLETYPE_BOOLEAN,
                'values'       => ['ON', 'OFF'],
                'profile'      => '~Switch',
                'enableAction' => (isset($feature['access']) && ($feature['access'] & 0b010) != 0),
                'ident'        => $featureId
            ];
        }

        // Prüfe auf vordefinierte States wenn kein state pattern matched
        return isset(static::$stateDefinitions[$featureId])
            ? static::$stateDefinitions[$featureId]
            : null;
    }

    /**
     * getFullRangeProfileName
     *
     * Erzeugt den vollständigen Namen eines Variablenprofils basierend auf den Expose-Daten.
     *
     * Diese Methode generiert den vollständigen Namen eines Variablenprofils für ein bestimmtes Feature
     * (Expose). Falls das Feature minimale und maximale Werte (`value_min`, `value_max`) enthält, werden
     * diese in den Profilnamen integriert.
     *
     * @param array $feature Ein Array, das die Eigenschaften des Features enthält.
     *
     * @return string Der vollständige Name des Variablenprofils.
     */
    private static function getFullRangeProfileName($feature): string
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
     * registerStateMappingProfile
     *
     * Handhabt die Erstellung eines Zustandsmusters (State Mapping) für ein gegebenes Identifikator.
     *
     * Diese Methode erstellt ein Variablenprofile. Das Profil enthält zwei Zustände,
     * die aus den vordefinierten Zustandsdefinitionen (`stateDefinitions`) abgeleitet werden.
     *
     * @param string $ProfileName Der ProfileName, für den das Zustandsmuster erstellt werden soll.
     *
     * @return string|null Der Name des erstellten Profils oder null, wenn kein Zustandsmuster existiert.
     *
     * @see \Zigbee2MQTT\ModulBase::RegisterProfileStringEx()
     * @see \IPSModule::SendDebug()
     */
    private function registerStateMappingProfile(string $ProfileName): ?string
    {
        $stateInfo = self::$stateDefinitions[$ProfileName];
        $this->RegisterProfileStringEx(
            $ProfileName,
            '',
            '',
            '',
            [
                [$stateInfo['values'][0], $stateInfo['values'][0], '', 0xFF0000],
                [$stateInfo['values'][1], $stateInfo['values'][1], '', 0x00FF00]
            ]
        );

        $this->SendDebug(__FUNCTION__, 'State mapping profile created for: ' . $ProfileName, 0);
        return $ProfileName;
    }
}
