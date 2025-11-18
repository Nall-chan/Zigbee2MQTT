<?php

declare(strict_types=1);

include_once __DIR__ . '/DumpInclude.php';

class DevicesTest extends DumpInclude
{
    public function testTRV06()
    {
        [$iid,$Debug] = $this->createTestInstance('TRV06.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        // schedule_* Variablen fehlen in $Debug['Childs'] Neues Z2M_Debug benötigt
        $this->assertSame(count($Debug['Childs']) + 7, count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(self::count_recursive($Debug['LastPayload']), count(IPS_GetChildrenIDs($iid)) - 1);
        // Weitere Tests möglich
    }

    public function test701721()
    {
        [$iid,$Debug] = $this->createTestInstance('701721.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        //$Debug['Childs'] ist leider unvollständig. Neues Z2M_Debug benötigt
        //$this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(self::count_recursive($Debug['LastPayload']), count(IPS_GetChildrenIDs($iid)) - 1);
        // Weitere Tests möglich
    }

    public function testTS130F()
    {
        [$iid,$Debug] = $this->createTestInstance('TS130F.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(self::count_recursive($Debug['LastPayload']), count(IPS_GetChildrenIDs($iid)) - 1);
        // Weitere Tests möglich
    }

    public function testWHD02()
    {
        [$iid,$Debug] = $this->createTestInstance('TS130F.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(self::count_recursive($Debug['LastPayload']), count(IPS_GetChildrenIDs($iid)) - 1);
        // Weitere Tests möglich
    }

    public function testTRVZB()
    {
        [$iid,$Debug] = $this->createTestInstance('TRVZB.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?

        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(self::count_recursive($Debug['LastPayload']), count(IPS_GetChildrenIDs($iid)) - 1);
        // Weitere Tests möglich
        //foreach (IPS_GetChildrenIDs($iid) as $id) {
        //    echo $id . ' -> ' . IPS_GetObject($id)['ObjectIdent'] . PHP_EOL;
        //}

    }

    public function testTS0601_thermostat()
    {
        [$iid,$Debug] = $this->createTestInstance('TS0601_thermostat.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        //$Debug['LastPayload'] ist leider unvollständig. Neues Z2M_Debug benötigt
        //$this->assertSame(self::count_recursive($Debug['LastPayload']), count(IPS_GetChildrenIDs($iid)) - 1);
        // Weitere Tests möglich
    }

    public function testRTCGQ01LM()
    {
        [$iid,$Debug] = $this->createTestInstance('RTCGQ01LM.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(self::count_recursive($Debug['LastPayload']), count(IPS_GetChildrenIDs($iid)) - 1);
        // Weitere Tests möglich
    }

    public function testMTD285_ZB()
    {
        [$iid,$Debug] = $this->createTestInstance('MTD285-ZB.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(self::count_recursive($Debug['LastPayload']), count(IPS_GetChildrenIDs($iid)) - 1);
        // Weitere Tests möglich
    }

    public function testAB3257001NJ()
    {
        [$iid,$Debug] = $this->createTestInstance('AB3257001NJ.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        $this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(self::count_recursive($Debug['LastPayload']), count(IPS_GetChildrenIDs($iid)) - 1);
        // Weitere Tests möglich
    }
    public function testPS_S04D()
    {
        [$iid,$Debug] = $this->createTestInstance('PS-S04D.json');
        // Wurden alle Variablen aus Payload verarbeitet und in Symcon angelegt?
        //$Debug['Childs'] ist leider unvollständig. Neues Z2M_Debug benötigt
        //$this->assertSame(count($Debug['Childs']), count(IPS_GetChildrenIDs($iid)));
        $this->assertSame(self::count_recursive($Debug['LastPayload']), count(IPS_GetChildrenIDs($iid)) - 1);
        // Weitere Tests möglich
    }

}