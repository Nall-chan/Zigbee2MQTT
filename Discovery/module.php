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

        /** @todo hier fehlt gaaaaanz viel */

        $this->SendDebug('Form', json_encode($Form), 0);
        return json_encode($Form);
    }

    public function checkAllMqttServers()
    {
        $Topics = [];
        foreach ($this->getAllMqTTSplitterInstances() as $SplitterId => $Config) {
            if (isset($Config['Host'])) { // client
                $Topics[$SplitterId] = $this->SearchBridges($Config);
            } else {  //server
                $Topics[$SplitterId] = MQTT_GetRetainedMessageTopicList($SplitterId);
            }
        }
        $this->SendDebug('Found Topics', json_encode($Topics), 0);
        return $Topics;
    }

    /**
     * SearchBridges
     *
     * @return ?array
     */
    public function SearchBridges(array $Config): ?array
    {
        $ClientId = IPS_GetName(0).IPS_GetLicensee();
        $mqtt = new \Zigbee2MQTT\phpMQTT($Config['Host'], $Config['Port'], $ClientId);
        if ($Config['UseSSL']) {
            $mqtt->setSslContextOptions(
                [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false
                    ]
                ]
            );
        }
        if(!$mqtt->connect(true, null, $Config['UserName'], $Config['Password'])) {
            return null;
        }


        $mqtt->subscribe(['+/bridge/state' => 0]);

        $i = 0;
        $Topics = [];
        while($i < 5) {
            $ret = $mqtt->proc();
            if (is_array($ret)) {
                $Topics[] = $ret[0];
                $ret = false;
            }
            $i++;
        }
        $mqtt->close();
        return count($Topics) ? $Topics : null;
    }

    /**
     * getAllMqTTSplitterInstances
     *
     * @return array
     */
    private function getAllMqTTSplitterInstances(): array
    {
        $MqttSplitter = [];
        foreach(IPS_GetInstanceListByModuleID(self::GUID_MQTT_SERVER) as $mqttInstanceId) {
            $MqttSplitter[$mqttInstanceId] = array_intersect_key(json_decode(IPS_GetConfiguration($mqttInstanceId), true), array_flip(['UserName','Password']));
        }
        foreach(IPS_GetInstanceListByModuleID(self::GUID_MQTT_CLIENT) as $mqttInstanceId) {
            $ioInstance = IPS_GetInstance($mqttInstanceId)['ConnectionID'];
            if ($ioInstance) {
                $MqttSplitter[$mqttInstanceId] =
                    array_merge(
                        array_intersect_key(json_decode(IPS_GetConfiguration($mqttInstanceId), true), array_flip(['UserName','Password'])),
                        array_intersect_key(json_decode(IPS_GetConfiguration($ioInstance), true), array_flip(['Host','Port','UseSSL']))
                    );
            }
        }
        $this->SendDebug('MQTTSplitter', json_encode($MqttSplitter), 0);
        return $MqttSplitter;
    }


}
