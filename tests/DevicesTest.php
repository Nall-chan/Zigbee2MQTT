<?php

declare(strict_types=1);

include_once __DIR__ . '/DumpInclude.php';

class DevicesTest extends DumpInclude
{
    public function testTRV06()
    {
        [$iid,$Payload] = $this->createTestInstance('TRV06.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Payload) - 1, count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }
    public function test701721()
    {
        [$iid,$Payload] = $this->createTestInstance('701721.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Payload) - 1, count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }
    public function testTS130F()
    {
        [$iid,$Payload] = $this->createTestInstance('TS130F.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Payload) - 1, count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }

    public function testWHD02()
    {
        [$iid,$Payload] = $this->createTestInstance('TS130F.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Payload) - 1, count(IPS_GetChildrenIDs($iid)));
        // Weitere Tests möglich
    }
}