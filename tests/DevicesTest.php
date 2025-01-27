<?php

declare(strict_types=1);

include_once __DIR__ . '/DumpInclude.php';

class DevicesTest extends DumpInclude
{
    public function testTRV06()
    {
        [$iid,$Debug] = $this->createTestInstance('TRV06.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(count($Debug['LastPayload'], COUNT_RECURSIVE), count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }
    public function test701721()
    {
        [$iid,$Debug] = $this->createTestInstance('701721.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(count($Debug['LastPayload'], COUNT_RECURSIVE), count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }
    public function testTS130F()
    {
        [$iid,$Debug] = $this->createTestInstance('TS130F.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(count($Debug['LastPayload'], COUNT_RECURSIVE), count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }

    public function testWHD02()
    {
        [$iid,$Debug] = $this->createTestInstance('TS130F.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(count($Debug['LastPayload'], COUNT_RECURSIVE), count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }

    public function testTRVZB()
    {
        [$iid,$Debug] = $this->createTestInstance('TRVZB.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?

        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(count($Debug['LastPayload'], COUNT_RECURSIVE), count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }
}