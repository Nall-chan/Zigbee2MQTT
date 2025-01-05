<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/BufferHelper.php';
require_once dirname(__DIR__) . '/libs/SemaphoreHelper.php';
require_once dirname(__DIR__) . '/libs/MQTTHelper.php';

class Zigbee2MQTTConfigurator extends IPSModule
{
    use \Zigbee2MQTT\BufferHelper;
    use \Zigbee2MQTT\Semaphore;
    use \Zigbee2MQTT\SendData;

    private static $DeviceValues = [
        'name'               => '',
        'id'                 => '',
        'parent'             => '',
        'instanceID'         => 0,
        'ieee_address'       => '',
        'topic'              => '',
        'networkAddress'     => '',
        'type'               => '',
        'vendor'             => '',
        'modelID'            => '',
        'description'        => '',
        'power_source'       => ''
    ];
    private static $GroupValues = [
        'name'               => '',
        'id'                 => '',
        'parent'             => '',
        'instanceID'         => 0,
        'ID'                 => 0,
        'topic'              => '',
        'DevicesCount'       => ''
    ];
    /**
     * Create
     *
     * @return void
     */
    public function Create()
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent(self::GUID_MQTT_SERVER);
        $this->RegisterPropertyString(self::MQTT_BASE_TOPIC, 'zigbee2mqtt');
        $this->TransactionData = [];
    }

    /**
     * ApplyChanges
     *
     * @return void
     */
    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $BaseTopic = $this->ReadPropertyString(self::MQTT_BASE_TOPIC);
        if (empty($BaseTopic)) {
            $this->SetStatus(IS_INACTIVE);
            $this->SetReceiveDataFilter('NOTHING_TO_RECEIVE'); //block all
            return;
        }
        $this->SetStatus(IS_ACTIVE);
        //Setze Filter für ReceiveData
        $this->SetReceiveDataFilter('.*"Topic":"' . $BaseTopic . self::SYMCON_EXTENSION_LIST_RESPONSE . '.*');
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
        switch ($ident) {
            case 'ReloadForm':
                $this->ReloadForm();
                break;
        }
    }

    /**
     * GetConfigurationForm
     *
     * @todo versuchen die bridge zu erreichen um zu testen ob das BaseTopic passt
     * @return string
     */
    public function GetConfigurationForm()
    {
        if ($this->GetStatus() == IS_CREATING) {
            return '';
        }
        $BaseTopic = $this->ReadPropertyString(self::MQTT_BASE_TOPIC);
        $Devices = [];
        $Groups = [];
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        if (!empty($BaseTopic)) {
            // todo versuchen die bridge zu erreichen um zu testen ob das BaseTopic passt
            /*if ($InfoResultDevices === false){
                $Form['actions'][0]['expanded'] = false;
                $Form['actions'][1]['expanded'] = false;
                $Form['actions'][2]['visible'] = true;
                $Form['actions'][2]['popup']['items'][0]['caption']='';
                $Form['actions'][2]['popup']['items'][1]['caption']='';
            }*/
            if (($this->HasActiveParent()) && (IPS_GetKernelRunlevel() == KR_READY)) {
                $Devices = $this->getDevices();
                $Groups = $this->getGroups();
            }
        }
        $this->SendDebug('NetworkDevices', json_encode($Devices), 0);
        $IPSDevicesByIEEE = $this->GetIPSInstancesByIEEE();
        $this->SendDebug('IPS Devices IEEE', json_encode($IPSDevicesByIEEE), 0);
        $IPSDevicesByTopic = $this->GetIPSInstancesByBaseTopic('{E5BB36C6-A70B-EB23-3716-9151A09AC8A2}', $BaseTopic);
        $this->SendDebug('IPS Devices Topic', json_encode($IPSDevicesByTopic), 0);
        if (!(count($Devices) + count($Groups))) {
            $Form['actions'][0]['expanded'] = false;
            $Form['actions'][1]['expanded'] = false;

            $BridgeIDs = array_filter(IPS_GetInstanceListByModuleID('{00160D82-9E2F-D1BD-6D0B-952F945332C5}'), [$this, 'FilterInstances']);
            $BridgeID = 0;
            foreach ($BridgeIDs as $BridgeID) {
                if (@IPS_GetProperty($BridgeID, self::MQTT_BASE_TOPIC) == $BaseTopic) {
                    break;
                }
            }
            if ($BridgeID) {
                $Form['actions'][2]['popup']['items'][2]['objectID'] = $BridgeID;
                $Form['actions'][2]['popup']['items'][2]['visible'] = true;
            } else {
                $Form['actions'][2]['popup']['items'][3]['onClick'] = [
                    '$BaseTopic= \'' . $BaseTopic . '\';',
                    '$SplitterId = ' . IPS_GetInstance($this->InstanceID)['ConnectionID'] . ';',
                    '$id = IPS_CreateInstance(\'{00160D82-9E2F-D1BD-6D0B-952F945332C5}\');',
                    'IPS_SetName($id, \'Zigbee2MQTT Bridge (\'.$BaseTopic.\')\');',
                    'if (IPS_GetInstance($id)[\'ConnectionID\'] != $SplitterId){',
                    '@IPS_DisconnectInstance($id);',
                    '@IPS_ConnectInstance($id, $SplitterId);',
                    '}',
                    '@IPS_SetProperty($id,\'MQTTBaseTopic\', $BaseTopic);',
                    '@IPS_ApplyChanges($id);',
                    'IPS_RequestAction(' . $this->InstanceID . ', \'ReloadForm\', true);'
                ];
                $Form['actions'][2]['popup']['items'][3]['visible'] = true;

            }
            $Form['actions'][2]['visible'] = true;
            return json_encode($Form);
        }
        //Devices
        $valuesDevices = [];
        $valueId = 1;
        foreach ($Devices as $device) {
            $value = []; //Array leeren
            $instanceID = array_search($device['ieeeAddr'], $IPSDevicesByIEEE);
            if ($instanceID) { //erst nach IEEE suchen
                unset($IPSDevicesByIEEE[$instanceID]);
                if (isset($IPSDevicesByTopic[$instanceID])) { //wenn auch in IPSDevicesByTopic vorhanden, hier löschen
                    unset($IPSDevicesByTopic[$instanceID]);
                }
            } else { // dann nach Topic suchen
                $instanceID = array_search($device['friendly_name'], $IPSDevicesByTopic);
                unset($IPSDevicesByTopic[$instanceID]);
                if (isset($IPSDevicesByIEEE[$instanceID])) { //wenn auch in IPSDevicesByIEEE vorhanden, hier löschen
                    unset($IPSDevicesByIEEE[$instanceID]);
                }
            }
            $Location = explode('/', $device['friendly_name']);
            $Name = array_pop($Location);
            if ($instanceID) {
                $value['name'] = IPS_GetName($instanceID);
                $value['instanceID'] = $instanceID;

            } else {
                $value['name'] = $Name;
                $value['instanceID'] = 0;
            }
            $value['parent'] = $this->AddParentElement($valueId, $valuesDevices, $Location, self::$DeviceValues);
            $value['id'] = $valueId++;
            $value['ieee_address'] = $device['ieeeAddr'];
            $value['topic'] = $device['friendly_name'];
            $value['networkAddress'] = $device['networkAddress'];
            $value['type'] = $device['type'];
            $value['vendor'] = $device['vendor'] ?? $this->Translate('Unknown');
            $value['modelID'] = $device['modelID'] ?? $this->Translate('Unknown');
            $value['description'] = $device['description'] ?? $this->Translate('Unknown');
            $value['power_source'] = isset($device['powerSource']) ? $this->Translate($device['powerSource']) : $this->Translate('Unknown');
            $value['create'] =
                [
                    'moduleID'      => '{E5BB36C6-A70B-EB23-3716-9151A09AC8A2}',
                    'location'      => $Location,
                    'configuration' => [
                        self::MQTT_BASE_TOPIC    => $BaseTopic,
                        self::MQTT_TOPIC         => $device['friendly_name'],
                        'IEEE'                   => $device['ieeeAddr']
                    ]
                ];
            array_push($valuesDevices, $value);
        }
        foreach ($IPSDevicesByIEEE as $instanceID => $IEEE) {
            $Topic = '';
            if (isset($IPSDevicesByTopic[$instanceID])) { //wenn auch in IPSDevicesByTopic vorhanden, hier löschen
                $Topic = $IPSDevicesByTopic[$instanceID];
                unset($IPSDevicesByTopic[$instanceID]);
            }
            $Location = explode('/', $Topic);
            array_pop($Location);
            $valuesDevices[] = [
                'name'               => IPS_GetName($instanceID),
                'id'                 => $valueId++,
                'parent'             => $this->AddParentElement($valueId, $valuesDevices, $Location, self::$DeviceValues),
                'instanceID'         => $instanceID,
                'ieee_address'       => $IEEE,
                'topic'              => $Topic,
                'networkAddress'     => '',
                'type'               => '',
                'vendor'             => '',
                'modelID'            => '',
                'description'        => '',
                'power_source'       => ''

            ];
        }
        foreach ($IPSDevicesByTopic as $instanceID => $Topic) {
            $Location = explode('/', $Topic);
            array_pop($Location);
            $valuesDevices[] = [
                'name'               => IPS_GetName($instanceID),
                'id'                 => $valueId++,
                'parent'             => $this->AddParentElement($valueId, $valuesDevices, $Location, self::$DeviceValues),
                'instanceID'         => $instanceID,
                'ieee_address'       => @IPS_GetProperty($instanceID, 'IEEE'),
                'topic'              => $Topic,
                'networkAddress'     => '',
                'type'               => '',
                'vendor'             => '',
                'modelID'            => '',
                'description'        => '',
                'power_source'       => ''

            ];
        }

        //Groups
        $this->SendDebug('NetworkGroups', json_encode($Groups), 0);
        $IPSGroupById = $this->GetIPSInstancesByGroupId();
        $this->SendDebug('IPS Group Id', json_encode($IPSGroupById), 0);
        $IPSGroupByTopic = $this->GetIPSInstancesByBaseTopic('{11BF3773-E940-469B-9DD7-FB9ACD7199A2}', $BaseTopic);
        $this->SendDebug('IPS Group Topic', json_encode($IPSGroupByTopic), 0);

        $valuesGroups = [];
        $valueId = 1;
        foreach ($Groups as $group) {
            $value = []; //Array leeren
            $instanceID = array_search($group['ID'], $IPSGroupById);
            if ($instanceID) { //erst nach ID suchen
                unset($IPSGroupById[$instanceID]);
                if (isset($IPSGroupByTopic[$instanceID])) { //wenn auch in IPSGroupByTopic vorhanden, hier löschen
                    unset($IPSGroupByTopic[$instanceID]);
                }
            } else { // dann nach Topic suchen
                $instanceID = array_search($group['friendly_name'], $IPSGroupByTopic);
                unset($IPSGroupByTopic[$instanceID]);
                if (isset($IPSGroupById[$instanceID])) { //wenn auch in IPSGroupById vorhanden, hier löschen
                    unset($IPSGroupById[$instanceID]);
                }
            }
            $Location = explode('/', $group['friendly_name']);
            $Name = array_pop($Location);
            if ($instanceID) {
                $value['name'] = IPS_GetName($instanceID);
                $value['instanceID'] = $instanceID;

            } else {
                $value['name'] = $Name;
                $value['instanceID'] = 0;
            }
            $value['parent'] = $this->AddParentElement($valueId, $valuesGroups, $Location, self::$GroupValues);
            $value['id'] = $valueId++;
            $value['ID'] = $group['ID'];
            $value['topic'] = $group['friendly_name'];
            $value['DevicesCount'] = (string) count($group['devices']);
            $value['create'] =
                [
                    'moduleID'      => '{11BF3773-E940-469B-9DD7-FB9ACD7199A2}',
                    'location'      => $Location,
                    'configuration' => [
                        self::MQTT_BASE_TOPIC    => $BaseTopic,
                        self::MQTT_TOPIC         => $group['friendly_name'],
                        'GroupId'                => $group['ID']
                    ]
                ];
            array_push($valuesGroups, $value);
        }
        foreach ($IPSGroupById as $instanceID => $ID) {
            $Topic = '';
            if (isset($IPSGroupByTopic[$instanceID])) { //wenn auch in IPSGroupByTopic vorhanden, hier löschen
                $Topic = $IPSGroupByTopic[$instanceID];
                unset($IPSGroupByTopic[$instanceID]);
            }
            $Location = explode('/', $Topic);
            array_pop($Location);
            $valuesGroups[] = [
                'name'                  => IPS_GetName($instanceID),
                'id'                    => $valueId++,
                'parent'                => $this->AddParentElement($valueId, $valuesDevices, $Location, self::$GroupValues),
                'instanceID'            => $instanceID,
                'ID'                    => $ID,
                'topic'                 => $Topic,
                'DevicesCount'          => ''

            ];
        }
        foreach ($IPSGroupByTopic as $instanceID => $Topic) {
            $Location = explode('/', $Topic);
            array_pop($Location);
            $valuesGroups[] = [
                'name'                  => IPS_GetName($instanceID),
                'id'                    => $valueId++,
                'parent'                => $this->AddParentElement($valueId, $valuesDevices, $Location, self::$DeviceValues),
                'instanceID'            => $instanceID,
                'ID'                    => @IPS_GetProperty($instanceID, 'GroupId'),
                'topic'                 => $Topic,
                'DevicesCount'          => ''

            ];
        }

        $Form['actions'][0]['items'][0]['values'] = $valuesDevices;
        $Form['actions'][0]['items'][0]['rowCount'] = (count($valuesDevices) < 20 ? count($valuesDevices) : 20);
        $Form['actions'][1]['items'][0]['values'] = $valuesGroups;
        $Form['actions'][1]['items'][0]['rowCount'] = (count($valuesGroups) < 20 ? count($valuesGroups) : 20);
        $this->SendDebug('Form', json_encode($Form), 0);
        return json_encode($Form);
    }

    /**
     * ReceiveData
     *
     * @param  string $JSONString
     * @return string
     */
    public function ReceiveData($JSONString)
    {
        if ($this->GetStatus() == IS_CREATING) {
            return '';
        }
        $BaseTopic = $this->ReadPropertyString(self::MQTT_BASE_TOPIC);
        if (empty($BaseTopic)) {
            return '';
        }
        $this->SendDebug('JSON', $JSONString, 0);
        $Buffer = json_decode($JSONString, true);

        if (!isset($Buffer['Topic'])) {
            return '';
        }

        $ReceiveTopic = $Buffer['Topic'];
        $this->SendDebug('MQTT FullTopic', $ReceiveTopic, 0);
        if (strpos($ReceiveTopic, $BaseTopic . self::SYMCON_EXTENSION_LIST_RESPONSE) !== 0) {
            return '';
        }
        $this->SendDebug('MQTT Topic', $ReceiveTopic, 0);
        $this->SendDebug('MQTT Payload', utf8_decode($Buffer['Payload']), 0);
        $Payload = json_decode(utf8_decode($Buffer['Payload']), true);
        if (isset($Payload['transaction'])) {
            $this->UpdateTransaction($Payload);
        }
        return '';
    }

    /**
     * getDevices
     *
     * @return array
     */
    public function getDevices()
    {
        $Result = @$this->SendData(self::SYMCON_EXTENSION_LIST_REQUEST . 'getDevices');
        if ($Result) {
            return $Result['list'];
        }
        return [];
    }

    /**
     * getGroups
     *
     * @return array
     */
    public function getGroups()
    {
        $Result = @$this->SendData(self::SYMCON_EXTENSION_LIST_REQUEST . 'getGroups');
        if ($Result) {
            return $Result['list'];
        }
        return [];
    }

    /**
     * TopicPathExists
     *
     * @param  array $values
     * @param  string $Topic
     * @return int|false
     */
    private function TopicPathExists(array $values, string $Topic)
    {
        foreach ($values as $dir) {
            if ($dir['topic'] === $Topic) {
                return $dir['id'];
            }
        }
        return false;
    }

    /**
     * AddParentElement
     *
     * @param  int $Id
     * @param  array $Result
     * @param  array $Topics
     * @param  array $EmtpyValuesArray
     * @return int
     */
    private function AddParentElement(int &$Id, array &$Result, array $Topics, array $EmtpyValuesArray): int
    {
        $parentId = 0; // Für root-Verzeichnisse setzen wir parent auf 0
        $currentPath = ''; // Der aktuelle Pfad wird hier zusammengebaut

        // Durchlaufe jedes Teil des Verzeichnisses
        foreach ($Topics as $Topic) {
            // Baue den aktuellen Pfad schrittweise auf
            $currentPath .= ($currentPath === '' ? '' : '/') . $Topic;

            // Prüfe, ob das Verzeichnis bereits existiert (nur anhand des vollständigen Pfads)
            $existingId = $this->TopicPathExists($Result, $currentPath);

            if ($existingId === false) {
                // Füge das Verzeichnis dem Ergebnis hinzu
                $Result[] = array_merge($EmtpyValuesArray, [
                    'id'      => $Id,
                    'topic'   => $currentPath, // Fügt den gesamten Pfad hinzu
                    'parent'  => $parentId, // Für root 0, sonst parentId des vorherigen Verzeichnisses
                    'expanded'=> true
                ]);

                // Setze den parentId für das nächste Verzeichnis
                $parentId = $Id;
                $Id++; // Erhöhe die ID für das nächste Verzeichnis
            } else {
                // Falls das Verzeichnis existiert, setze parentId auf die ID des existierenden Verzeichnisses
                $parentId = $existingId;
            }
        }
        return $parentId;
    }
    /**
     * GetIPSInstancesByIEEE
     *
     * @return array
     */
    private function GetIPSInstancesByIEEE(): array
    {
        $Devices = [];
        $InstanceIDList = array_filter(IPS_GetInstanceListByModuleID('{E5BB36C6-A70B-EB23-3716-9151A09AC8A2}'), [$this, 'FilterInstances']);
        foreach ($InstanceIDList as $InstanceID) {
            $Devices[$InstanceID] = @IPS_GetProperty($InstanceID, 'IEEE');
        }
        return array_filter($Devices);
    }

    /**
     * GetIPSInstancesByGroupId
     *
     * @return array
     */
    private function GetIPSInstancesByGroupId(): array
    {
        $Devices = [];

        $InstanceIDList = array_filter(IPS_GetInstanceListByModuleID('{11BF3773-E940-469B-9DD7-FB9ACD7199A2}'), [$this, 'FilterInstances']);
        foreach ($InstanceIDList as $InstanceID) {
            $Devices[$InstanceID] = @IPS_GetProperty($InstanceID, 'GroupId');
        }
        return array_filter($Devices);
    }

    /**
     * GetIPSInstancesByBaseTopic
     *
     * @param  string $GUID
     * @param  string $BaseTopic
     * @return array
     */
    private function GetIPSInstancesByBaseTopic(string $GUID, string $BaseTopic): array
    {
        $Devices = [];
        $InstanceIDList = array_filter(IPS_GetInstanceListByModuleID($GUID), [$this, 'FilterInstances']);
        foreach ($InstanceIDList as $InstanceID) {
            if (@IPS_GetProperty($InstanceID, 'MQTTBaseTopic') == $BaseTopic) {
                $Devices[$InstanceID] = @IPS_GetProperty($InstanceID, self::MQTT_TOPIC);
            }
        }
        return $Devices;
    }

    /**
     * FilterInstances
     *
     * @param  int $InstanceID
     * @return bool
     */
    private function FilterInstances(int $InstanceID): bool
    {
        return (
            IPS_GetInstance($InstanceID)['ConnectionID'] == IPS_GetInstance($this->InstanceID)['ConnectionID']
        ) && (
            IPS_GetProperty($InstanceID, self::MQTT_BASE_TOPIC) == IPS_GetProperty($this->InstanceID, self::MQTT_BASE_TOPIC)
        );
    }
}