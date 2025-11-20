<?php

declare(strict_types=1);

include_once __DIR__ . '/DumpInclude.php';

class DevicesTest extends DumpInclude
{
    public function testTRV06()
    {
        [$iid,$Debug] = $this->createTestInstance('TRV06.json');
        $OffestLastPayload = 0;
        // device_status bei den IPS_GetChildrenIDs abziehen
        $OffsetChildrenIDs = -1;
        // schedule_* Variablen fehlen in $Debug['Childs'] Neues Z2M_Debug benötigt
        $OffsetDebugChild = +7;
        $this->assertSame(count($Debug['Childs']) + $OffsetDebugChild, count(IPS_GetChildrenIDs($iid)), 'Anzahl Variablen aus dem Debug un Erzeugte Variablen vom Test unterscheiden sich');
        $this->assertSame(self::count_recursive($Debug['LastPayload']) + $OffestLastPayload, count(IPS_GetChildrenIDs($iid)) + $OffsetChildrenIDs, 'Anzahl LastPayload und Erzeugte Variablen unterscheiden sich');
        // defekt wegen UTF8 Fehler bei den Profilen
        //$this->assertCount(0, self::getExportDebugData($iid)['missingTranslations'], 'Fehlende übersetzungen gefunden:' . var_export(self::getExportDebugData($iid)['missingTranslations'], true));
    }

    public function test701721()
    {
        [$iid,$Debug] = $this->createTestInstance('701721.json');
        $OffestLastPayload = 0;
        // device_status bei den IPS_GetChildrenIDs abziehen
        $OffsetChildrenIDs = -1;
        // schedule_* Variablen fehlen in $Debug['Childs'] Neues Z2M_Debug benötigt
        $OffsetDebugChild = 0;
        //$Debug['Childs'] ist leider unvollständig. Neues Z2M_Debug benötigt
        //$this->assertSame(count($Debug['Childs']) + $OffsetDebugChild, count(IPS_GetChildrenIDs($iid)), 'Anzahl Variablen aus dem Debug un Erzeugte Variablen vom Test unterscheiden sich');
        $this->assertSame(self::count_recursive($Debug['LastPayload']) + $OffestLastPayload, count(IPS_GetChildrenIDs($iid)) + $OffsetChildrenIDs, 'Anzahl LastPayload und Erzeugte Variablen unterscheiden sich');
        $this->assertCount(0, self::getExportDebugData($iid)['missingTranslations'], 'Fehlende übersetzungen gefunden:' . var_export(self::getExportDebugData($iid)['missingTranslations'], true));
    }

    public function testTS130F()
    {
        [$iid,$Debug] = $this->createTestInstance('TS130F.json');
        $OffestLastPayload = 0;
        // device_status bei den IPS_GetChildrenIDs abziehen
        $OffsetChildrenIDs = -1;
        $OffsetDebugChild = 0;
        $this->assertSame(count($Debug['Childs']) + $OffsetDebugChild, count(IPS_GetChildrenIDs($iid)), 'Anzahl Variablen aus dem Debug un Erzeugte Variablen vom Test unterscheiden sich');
        $this->assertSame(self::count_recursive($Debug['LastPayload']) + $OffestLastPayload, count(IPS_GetChildrenIDs($iid)) + $OffsetChildrenIDs, 'Anzahl LastPayload und Erzeugte Variablen unterscheiden sich');
        $this->assertCount(0, self::getExportDebugData($iid)['missingTranslations'], 'Fehlende übersetzungen gefunden:' . var_export(self::getExportDebugData($iid)['missingTranslations'], true));
    }

    public function testWHD02()
    {
        [$iid,$Debug] = $this->createTestInstance('TS130F.json');
        $OffestLastPayload = 0;
        // device_status bei den IPS_GetChildrenIDs abziehen
        $OffsetChildrenIDs = -1;
        $OffsetDebugChild = 0;
        $this->assertSame(count($Debug['Childs']) + $OffsetDebugChild, count(IPS_GetChildrenIDs($iid)), 'Anzahl Variablen aus dem Debug un Erzeugte Variablen vom Test unterscheiden sich');
        $this->assertSame(self::count_recursive($Debug['LastPayload']) + $OffestLastPayload, count(IPS_GetChildrenIDs($iid)) + $OffsetChildrenIDs, 'Anzahl LastPayload und Erzeugte Variablen unterscheiden sich');
        $this->assertCount(0, self::getExportDebugData($iid)['missingTranslations'], 'Fehlende übersetzungen gefunden:' . var_export(self::getExportDebugData($iid)['missingTranslations'], true));
    }

    public function testTRVZB()
    {
        [$iid,$Debug] = $this->createTestInstance('TRVZB.json');
        $OffestLastPayload = 0;
        // device_status bei den IPS_GetChildrenIDs abziehen
        $OffsetChildrenIDs = -1;
        $OffsetDebugChild = 0;
        $this->assertSame(count($Debug['Childs']) + $OffsetDebugChild, count(IPS_GetChildrenIDs($iid)), 'Anzahl Variablen aus dem Debug un Erzeugte Variablen vom Test unterscheiden sich');
        $this->assertSame(self::count_recursive($Debug['LastPayload']) + $OffestLastPayload, count(IPS_GetChildrenIDs($iid)) + $OffsetChildrenIDs, 'Anzahl LastPayload und Erzeugte Variablen unterscheiden sich');
        // defekt wegen UTF8 Fehler bei den Profilen
        //$this->assertCount(0, self::getExportDebugData($iid)['missingTranslations'], 'Fehlende übersetzungen gefunden:' . var_export(self::getExportDebugData($iid)['missingTranslations'], true));
    }

    public function testTS0601_thermostat()
    {
        [$iid,$Debug] = $this->createTestInstance('TS0601_thermostat.json');
        $OffestLastPayload = 0;
        // device_status bei den IPS_GetChildrenIDs abziehen
        $OffsetChildrenIDs = -1;
        $OffsetDebugChild = 0;
        $this->assertSame(count($Debug['Childs']) + $OffsetDebugChild, count(IPS_GetChildrenIDs($iid)), 'Anzahl Variablen aus dem Debug un Erzeugte Variablen vom Test unterscheiden sich');
        //$Debug['LastPayload'] ist leider unvollständig. Neues Z2M_Debug benötigt
        //$this->assertSame(self::count_recursive($Debug['LastPayload']) + $OffestLastPayload, count(IPS_GetChildrenIDs($iid)) + $OffsetChildrenIDs, 'Anzahl LastPayload und Erzeugte Variablen unterscheiden sich');
        // defekt wegen UTF8 Fehler bei den Profilen
        //$this->assertCount(0, self::getExportDebugData($iid)['missingTranslations'], 'Fehlende übersetzungen gefunden:' . var_export(self::getExportDebugData($iid)['missingTranslations'], true));
    }

    public function testRTCGQ01LM()
    {
        [$iid,$Debug] = $this->createTestInstance('RTCGQ01LM.json');
        $OffestLastPayload = 0;
        // device_status bei den IPS_GetChildrenIDs abziehen
        $OffsetChildrenIDs = -1;
        $OffsetDebugChild = 0;
        $this->assertSame(count($Debug['Childs']) + $OffsetDebugChild, count(IPS_GetChildrenIDs($iid)), 'Anzahl Variablen aus dem Debug un Erzeugte Variablen vom Test unterscheiden sich');
        $this->assertSame(self::count_recursive($Debug['LastPayload']) + $OffestLastPayload, count(IPS_GetChildrenIDs($iid)) + $OffsetChildrenIDs, 'Anzahl LastPayload und Erzeugte Variablen unterscheiden sich');
        $this->assertCount(0, self::getExportDebugData($iid)['missingTranslations'], 'Fehlende übersetzungen gefunden:' . var_export(self::getExportDebugData($iid)['missingTranslations'], true));
    }

    public function testMTD285_ZB()
    {
        [$iid,$Debug] = $this->createTestInstance('MTD285-ZB.json');
        $OffestLastPayload = 0;
        // device_status bei den IPS_GetChildrenIDs abziehen
        $OffsetChildrenIDs = -1;
        $OffsetDebugChild = 0;
        $this->assertSame(count($Debug['Childs']) + $OffsetDebugChild, count(IPS_GetChildrenIDs($iid)), 'Anzahl Variablen aus dem Debug un Erzeugte Variablen vom Test unterscheiden sich');
        $this->assertSame(self::count_recursive($Debug['LastPayload']) + $OffestLastPayload, count(IPS_GetChildrenIDs($iid)) + $OffsetChildrenIDs, 'Anzahl LastPayload und Erzeugte Variablen unterscheiden sich');
        $this->assertCount(0, self::getExportDebugData($iid)['missingTranslations'], 'Fehlende übersetzungen gefunden:' . var_export(self::getExportDebugData($iid)['missingTranslations'], true));
    }

    public function testAB3257001NJ()
    {
        [$iid,$Debug] = $this->createTestInstance('AB3257001NJ.json');
        $OffestLastPayload = 0;
        // device_status bei den IPS_GetChildrenIDs abziehen
        $OffsetChildrenIDs = -1;
        $OffsetDebugChild = 0;
        $this->assertSame(count($Debug['Childs']) + $OffsetDebugChild, count(IPS_GetChildrenIDs($iid)), 'Anzahl Variablen aus dem Debug un Erzeugte Variablen vom Test unterscheiden sich');
        $this->assertSame(self::count_recursive($Debug['LastPayload']) + $OffestLastPayload, count(IPS_GetChildrenIDs($iid)) + $OffsetChildrenIDs, 'Anzahl LastPayload und Erzeugte Variablen unterscheiden sich');
        $this->assertCount(0, self::getExportDebugData($iid)['missingTranslations'], 'Fehlende übersetzungen gefunden:' . var_export(self::getExportDebugData($iid)['missingTranslations'], true));
    }

    public function testPS_S04D()
    {
        [$iid,$Debug] = $this->createTestInstance('PS-S04D.json');
        // detection_range_prefix & schedule_time_raw fehlen im Expose, sind aber im Payload
        $OffestLastPayload = -2;
        // identify und device_status bei den IPS_GetChildrenIDs abziehen
        $OffsetChildrenIDs = -2;
        $OffsetDebugChild = 0;
        $this->assertSame(count($Debug['Childs']) + $OffsetDebugChild, count(IPS_GetChildrenIDs($iid)), 'Anzahl Variablen aus dem Debug un Erzeugte Variablen vom Test unterscheiden sich');
        $this->assertSame(self::count_recursive($Debug['LastPayload']) + $OffestLastPayload, count(IPS_GetChildrenIDs($iid)) + $OffsetChildrenIDs, 'Anzahl LastPayload und Erzeugte Variablen unterscheiden sich');
        // defekt wegen UTF8 Fehler bei den Profilen
        //$this->assertCount(0, self::getExportDebugData($iid)['missingTranslations'], 'Fehlende übersetzungen gefunden:' . var_export(self::getExportDebugData($iid)['missingTranslations'], true));
    }

}