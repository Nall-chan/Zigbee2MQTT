<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/libs/ModuleConstants.php';
require_once dirname(__DIR__) . '/libs/BufferHelper.php';
require_once dirname(__DIR__) . '/libs/phpMQTT.php';

class Zigbee2MQTTDiscovery extends IPSModule
{
    use Zigbee2MQTT\Constants;

    /**
     * Create
     *
     * @return void
     */
    public function Create()
    {
        //Never delete this line!
        parent::Create();
        //$this->TransactionData = [];
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
        $Form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $FoundZ2mBySplitterId = $this->checkAllMqttServers();
        $this->SendDebug('Found Zigbee2MQTT', json_encode($FoundZ2mBySplitterId), 0);
        $IPSConfigurators = $this->GetConfigurators();
        $this->SendDebug('Known Configurators', json_encode($IPSConfigurators), 0);
        $Values = [];

        foreach ($FoundZ2mBySplitterId as $SplitterId => $Topics) {
            foreach ($Topics as $key => $Topic) {
                $instanceID = array_search($Topic, $IPSConfigurators);
                if ($instanceID) {
                    unset($IPSConfigurators[$instanceID]);
                }
                // Konfigeintrag mit Kette zu SplitterId
                $value = []; //Array leeren
                if ($instanceID) {
                    $value['name'] = IPS_GetName($instanceID);
                    $value['instanceID'] = $instanceID;

                } else {
                    $value['name'] = $Topic;
                    $value['instanceID'] = 0;
                }
                $value['topic'] = $Topic;
                $value['create'] = [
                    [
                        'moduleID'      => self::GUID_MODULE_CONFIGURATOR,
                        'configuration' => [
                            self::MQTT_BASE_TOPIC    => $Topic
                        ]
                    ],
                    [
                        'moduleID'      => IPS_GetInstance($SplitterId)['ModuleInfo']['ModuleID'],
                        'configuration' => json_decode(IPS_GetConfiguration($SplitterId), true)
                    ]
                ];
                $Values[] = $value;
            }
        }
        foreach ($IPSConfigurators as $instanceID => $Topic) {
            // nicht gefundene Bridges hier in rot auflisten
        }
        $Form['actions'][0]['values'] = $Values;
        $this->SendDebug('Form', json_encode($Form), 0);
        return json_encode($Form);
    }

    /**
     * GetConfigurators
     *
     * @return array
     */
    private function GetConfigurators(): array
    {
        $ConfiguratorList = [];
        $InstanceIDList = IPS_GetInstanceListByModuleID(self::GUID_MODULE_CONFIGURATOR);
        foreach ($InstanceIDList as $InstanceID) {
            $ConfiguratorList[$InstanceID] = @IPS_GetProperty($InstanceID, self::MQTT_BASE_TOPIC);
        }
        return array_filter($ConfiguratorList);
    }

    /**
     * SearchBridges
     * @param array $Config
     * @return ?array
     */
    private function SearchBridges(array $Config): ?array
    {
        $ClientId = IPS_GetName(0) . IPS_GetLicensee();
        $mqtt = new \Zigbee2MQTT\phpMQTT($Config['Host'], $Config['Port'], $ClientId);
        if ($Config['UseSSL']) {
            $mqtt->setSslContextOptions(
                [
                    'ssl' => [
                        'verify_peer'      => false,
                        'verify_peer_name' => false
                    ]
                ]
            );
        }
        if (!$mqtt->connect(true, null, $Config['UserName'], $Config['Password'])) {
            return null;
        }

        $mqtt->subscribe(['+/bridge/state' => 0]);

        $i = 0;
        $Topics = [];
        while ($i < 5) {
            $ret = $mqtt->proc();
            if (is_array($ret)) {
                $this->SendDebug('Receive ' . $ret[0], $ret[1], 0);
                if ($ret[1] === '{"state":"online"}') {
                    $Topics[] = strstr($ret[0], '/', true);
                }
                $ret = false;
            }
            $i++;
        }
        $mqtt->close();
        return count($Topics) ? array_unique($Topics) : null;
    }

    /**
     * checkAllMqttServers
     *
     * @return array
     */
    private function checkAllMqttServers(): array
    {
        $Topics = [];
        foreach ($this->getAllMqTTSplitterInstances() as $SplitterId => $Config) {
            if (isset($Config['Host'])) { // client
                $Topics[$SplitterId] = $this->SearchBridges($Config);
            } else {  //server
                foreach (array_filter(MQTT_GetRetainedMessageTopicList($SplitterId), [$this, 'FilterTopics']) as $Topic) {
                    $Found = [];
                    if (MQTT_GetRetainedMessage($SplitterId, $Topic)['Payload'] == '{"state":"online"}') {
                        $Found[] = strstr($Topic, '/', true);
                    }
                    $Topics[$SplitterId] = array_unique($Found);
                }
            }
        }
        return $Topics;
    }

    /**
     * FilterTopics
     *
     * @param  mixed $Topic
     * @return bool
     */
    private function FilterTopics(string $Topic): bool
    {
        $Topics = explode('/', $Topic);
        array_shift($Topics);
        return implode('/', $Topics) === 'bridge/state';
    }
    /**
     * getAllMqTTSplitterInstances
     *
     * @return array
     */
    private function getAllMqTTSplitterInstances(): array
    {
        $MqttSplitter = [];
        foreach (IPS_GetInstanceListByModuleID(self::GUID_MQTT_SERVER) as $mqttInstanceId) {
            $MqttSplitter[$mqttInstanceId] = array_intersect_key(json_decode(IPS_GetConfiguration($mqttInstanceId), true), array_flip(['UserName', 'Password']));
        }
        foreach (IPS_GetInstanceListByModuleID(self::GUID_MQTT_CLIENT) as $mqttInstanceId) {
            $ioInstance = IPS_GetInstance($mqttInstanceId)['ConnectionID'];
            if ($ioInstance) {
                $MqttSplitter[$mqttInstanceId] =
                    array_merge(
                        array_intersect_key(json_decode(IPS_GetConfiguration($mqttInstanceId), true), array_flip(['UserName', 'Password'])),
                        array_intersect_key(json_decode(IPS_GetConfiguration($ioInstance), true), array_flip(['Host', 'Port', 'UseSSL']))
                    );
            }
        }
        $this->SendDebug('MQTTSplitter', json_encode($MqttSplitter), 0);
        return $MqttSplitter;
    }

}
