<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/GlobalStubs.php';
include_once __DIR__ . '/stubs/KernelStubs.php';
include_once __DIR__ . '/stubs/MessageStubs.php';
include_once __DIR__ . '/stubs/ModuleStubs.php';

use PHPUnit\Framework\TestCase;

class BasicFunctionalityTest extends TestCase
{
    private $deviceModuleID = '{E5BB36C6-A70B-EB23-3716-9151A09AC8A2}';
    private $groupControlID = '{11BF3773-E940-469B-9DD7-FB9ACD7199A2}';

    public function setUp(): void
    {
        //Reset
        IPS\Kernel::reset();

        //Register our library we need for testing
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/stubs/CoreStubs/library.json');

        //Load required actions
        IPS\ActionPool::loadActions(__DIR__ . '/actions');

        parent::setUp();
    }
    public function testNop(): void
    {
        $this->assertTrue(true);
    }

    public function testCreate()
    {
        $previousCount = count(IPS_GetInstanceListByModuleID($this->deviceModuleID));
        IPS_CreateInstance($this->deviceModuleID);
        $this->assertEquals($previousCount + 1, count(IPS_GetInstanceListByModuleID($this->deviceModuleID)));
    }

    public function testPayload()
    {
        $Payload = '{"last_seen":1736083201892,"linkquality":61,"power_on_behavior":"off","state":"OFF"}';
        //$Topic = '';
        $iid = IPS_CreateInstance($this->deviceModuleID);
        $this->assertTrue(IPS\InstanceManager::getInstanceInterface($iid) instanceof Zigbee2MQTTDevice);
        IPS_SetConfiguration($iid, json_encode([
            'IEEE'         => '0xf082c0fffe293ae3',
            'MQTTBaseTopic'=> 'zigbee2mqtt',
            'MQTTTopic'    => 'Außen/Terrasse/Licht Terrasse'
        ]));
        IPS_ApplyChanges($iid);
        $intf = IPS\InstanceManager::getInstanceInterface($iid);
        $this->assertTrue($intf instanceof Zigbee2MQTTDevice);
    }

}