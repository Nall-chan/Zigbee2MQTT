<?php

declare(strict_types=1);

include_once __DIR__ . '/DumpInclude.php';

class DevicesTest extends DumpInclude
{
    public function _testTRV06()
    {
        [$iid,$Debug] = $this->createTestInstance('TRV06.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(count($Debug['LastPayload'], COUNT_RECURSIVE), count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }
    public function _test701721()
    {
        [$iid,$Debug] = $this->createTestInstance('701721.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(count($Debug['LastPayload'], COUNT_RECURSIVE), count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }
    public function _testTS130F()
    {
        [$iid,$Debug] = $this->createTestInstance('TS130F.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(count($Debug['LastPayload'], COUNT_RECURSIVE), count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }

    public function _testWHD02()
    {
        [$iid,$Debug] = $this->createTestInstance('TS130F.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(count($Debug['LastPayload'], COUNT_RECURSIVE), count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }

    public function _testTRVZB()
    {
        [$iid,$Debug] = $this->createTestInstance('TRVZB.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?

        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(count($Debug['LastPayload'], COUNT_RECURSIVE), count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }

    public function _testTS0601_thermostat()
    {
        [$iid,$Debug] = $this->createTestInstance('TS0601_thermostat.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(count($Debug['LastPayload'], COUNT_RECURSIVE), count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }
}