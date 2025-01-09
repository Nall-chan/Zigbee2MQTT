[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-7.0%20%3E-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)  
[![Check Style](https://github.com/Schnittcher/IPS-Zigbee2MQTT/workflows/Check%20Style/badge.svg)](https://github.com/Schnittcher/IPS-Zigbee2MQTT/actions)
[![Run Tests](https://github.com/Schnittcher/IPS-Zigbee2MQTT/workflows/Run%20Tests/badge.svg)](https://github.com/Schnittcher/IPS-Zigbee2MQTT/actions)  

# Zigbee2MQTT-Bridge  <!-- omit in toc -->
   Modul für alle Systemweiten Funktionen von Zigbee2MQTT


## Inhaltverzeichnis <!-- omit in toc -->

- [1. Funktionsumfang](#1-funktionsumfang)
- [2. Voraussetzungen](#2-voraussetzungen)
- [3. Software-Installation](#3-software-installation)
- [4. Konfiguration](#4-konfiguration)
- [5. Statusvariablen](#5-statusvariablen)
- [6. PHP-Funktionsreferenz](#6-php-funktionsreferenz)
- [7. Aktionen](#7-aktionen)
- [8. Anhang](#8-anhang)
  - [1. Changelog](#1-changelog)
  - [2. Spenden](#2-spenden)
  - [3. Lizenz](#3-lizenz)

## 1. Funktionsumfang

* Verfügbarkeit von Zigbee2MQTT in Symcon darstellen (Online-Variable)
* Verwaltung der für das Modul benötigten Extension in Zigbee2MQTT
* Systemweite Einstellungen in Zigbee2MQTT aus Symcon anpassen
* Netzwerkbeitritt aus Symcon steuern und darstellen
* Viele PHP-Funktionen um interne Zigbee2MQTT Funktionen auszuführen (Gruppen verwalten, Geräte umbenennen usw...)
  
## 2. Voraussetzungen

* mindestens IPS Version 7.0
* MQTT-Broker (interner MQTT-Server von Symcon oder externer z.B. Mosquitto)
* installiertes und lauffähiges [zigbee2mqtt](https://www.zigbee2mqtt.io) 
  
## 3. Software-Installation

* Dieses Modul ist Bestandteil der [Zigbee2MQTT-Library](../README.md#3-installation).  

## 4. Konfiguration

TODO

## 5. Statusvariablen

| Name                               | Typ     | Profil              | Beschreibung                                 |
| ---------------------------------- | ------- | ------------------- | -------------------------------------------- |
| Beitritt zum Netzwerk zulassen     | bool    | ~Switch             | Status und Steuern des Netzwerkbeitritt      |
| Erweiterung geladen                | bool    |                     | true wenn die Erweiterung geladen wurde      |
| Erweiterung ist aktuell            | bool    |                     | true wenn die Erweiterung aktuell ist        |
| Erweiterung Version                | string  |                     | Version der Erweiterung                      |
| Netzwerkkanal                      | integer |                     | Netzwerkkanal des Zigbee-Netzwerks           |
| Neustart durchführen               | integer | Z2M.bridge.restart  | Action Variable um einen Neustart auszulösen |
| Neustart erforderlich              | bool    |                     | true wenn eine Neustart von Z2M nötig ist    |
| Protokollierung                    | string  | Z2M.brigde.loglevel | Status der Softwareaktualisierung            |
| Status                             | bool    | ~Alert.Reversed     | Online Status von Zigbee2MQTT                |
| Version                            | string  |                     | Version von Zigbee2MQTT                      |
| Zigbee Herdsman Converters Version | string  |                     | Version des Zigbee Herdsman Converters       |
| Zigbee Herdsman Version            | string  |                     | Version vom Zigbee Herdsman-Modul            |

## 6. PHP-Funktionsreferenz

```php
bool Z2M_InstallSymconExtension(int $InstanzID);
```
Die aktuelle Symcon Erweiterung wird in Z2M installiert.  

--

```php
bool Z2M_SetLastSeen(int $InstanzID);
```
Die Konfiguration der `last_seen` Einstellung in Z2M wird auf `epoch` verändert, damit die Instanzen in Symcon den Wert korrekt darstellen können.  

--
```php
bool Z2M_SetPermitJoin(int $InstanzID, bool $PermitJoin);
```

--
```php
bool Z2M_SetLogLevel(int $InstanzID, string $LogLevel);
```

--
```php
bool Z2M_Restart(int $InstanzID);
```

--
```php
bool Z2M_CreateGroup(int $InstanzID, string $GroupName);
```

--
```php
bool Z2M_DeleteGroup(int $InstanzID, string $GroupName);
```

--
```php
bool Z2M_RenameGroup(int $InstanzID, string $OldName, string $NewName);
```

--
```php
bool Z2M_AddDeviceToGroup(int $InstanzID, string $GroupName, string $DeviceName);
```

--
```php
bool Z2M_RemoveDeviceFromGroup(int $InstanzID, string $GroupName, string $DeviceName);
```

--
```php
bool Z2M_RemoveAllDevicesFromGroup(int $InstanzID, string $GroupName);
```

--
```php
bool Z2M_Bind(int $InstanzID, string $SourceDevice, string $TargetDevice);
```

--
```php
bool Z2M_Unbind(int $InstanzID, string $SourceDevice, string $TargetDevice);
```

--
```php
bool Z2M_RequestNetworkmap(int $InstanzID);
```

--
```php
bool Z2M_RenameDevice(int $InstanzID, string $OldDeviceName, string $NewDeviceName);
```

--
```php
bool Z2M_RemoveDevice(int $InstanzID, string $DeviceName);
```

--
```php
bool Z2M_CheckOTAUpdate(int $InstanzID, string $DeviceName);
```

--
```php
bool Z2M_PerformOTAUpdate(int $InstanzID, string $DeviceName);
```

## 7. Aktionen

Keine Aktionen verfügbar.

## 8. Anhang

### 1. Changelog

[Changelog der Library](../README.md#5-changelog)

### 2. Spenden

Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=EK4JRP87XLSHW" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a> <a href="https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share" target="_blank">Amazon Wunschzettel</a>

### 3. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)