[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-7.0%20%3E-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)  
[![Check Style](https://github.com/Schnittcher/IPS-Zigbee2MQTT/workflows/Check%20Style/badge.svg)](https://github.com/Schnittcher/IPS-Zigbee2MQTT/actions)
[![Run Tests](https://github.com/Schnittcher/IPS-Zigbee2MQTT/workflows/Run%20Tests/badge.svg)](https://github.com/Schnittcher/IPS-Zigbee2MQTT/actions)  

# Zigbee2MQTT-Konfigurator <!-- omit in toc -->
Mit dieser Instanz werden die Geräte und Gruppen aus Zigbee2MQTT ausgelesen und können in Symcon als Instanz angelegt werden.

## Inhaltsverzeichnis <!-- omit in toc -->
- [1. Voraussetzungen](#1-voraussetzungen)
- [2. Software-Installation](#2-software-installation)
- [3. Verwendung der Instanzen](#3-verwendung-der-instanzen)
- [4. Statusvariablen](#4-statusvariablen)
- [5. PHP-Funktionsreferenz](#5-php-funktionsreferenz)
- [6. Aktionen](#6-aktionen)
- [7. Anhang](#7-anhang)
  - [1. Changelog](#1-changelog)
  - [2. Spenden](#2-spenden)
  - [3. Lizenz](#3-lizenz)


## 1. Voraussetzungen

* mindestens IPS Version 7.0
* MQTT-Broker (interner MQTT-Server von Symcon oder externer z.B. Mosquitto)
* installiertes und lauffähiges [zigbee2mqtt](https://www.zigbee2mqtt.io) 
  
## 2. Software-Installation

* Dieses Modul ist Bestandteil der [Zigbee2MQTT-Library](../README.md#3-installation).  

## 3. Verwendung der Instanzen

TODO

![Übersicht Konfigurator](/docs/pictures/konfigurator_ansicht.jpg)

Nummer | Name | Beschreibung
------------ | ------------- | -------------
**1** | **Gateway ändern** | ![Gateway ändern](/docs/pictures/konfigurator_gatewayauswahl.jpg) <br>Hier gebt Ihr den MQTT-Knotenpunkt an. Wenn Ihr den Symcon internen MQTT-Broker nutzt, sehr Ihr dort die MQTT-Server Device. Wenn Ihr einen anderen Broker nutzt (z.B. Mosquitto) dann seht Ihr hier die MQTT-Klient-Device, die auf den Mosquitto-Broker zugreift.
**2** | **Gateway konfigurieren** | ![Gateway konfigurieren](/docs/pictures/konfigurator_Gateway_konfigurieren.jpg) Unter diesem Punkt kann das MQTT-Gateway direkt aufgerufen werden, falls Ihr dort Änderungen vornehmen wollt.
**3** | **IEEE Adresse** | Zeigt die unveränderbare IEEE-Adresse der Zigbee-Devices
**4** | **Friendlyname** | Gibt den in Zigbee2MQTT (Z2M) angegebenen friendly_name an. <br> **WICHTIG: Wenn Ihr eine Struktur in die MQTT-Topics kriegen wollt, dann könnt ihr im friendly_name slashes nutzen (Etage/Raum/Sparte/Gerät) Durch den Slash wird dann die MQTT-Baumstruktur von Z2M aufgebaut:** <br>![MQTT Struktur](/docs/pictures/mqtt_struktur.jpg)
**5** | **Hersteller** | Gibt an, von welchem Hersteller das Device ist.
**6** | **Model ID** | Die Geräte-Model ID des Herstellers
**7** | **Beschreibung** | Gibt den Geräte-Typ an (z.B. Smoke-Detector, Plug, Aqara door & window contact sensor, etc.)
**8** | **Energiequelle** | Gibt an, ob das Gerät mit Batterie oder Netzspannung versorgt ist.<br> **Wichtig um zu sehen, ob das Gerät als Router genutzt werden kann: Batterie = Nein, Netz = Ja**
**9** | **InstanzID** | Daran lässt sich zum einen erkennen, ob das Gerät bereits in Symcon angelegt ist und mit welcher Objekt-ID oder ob es noch nicht angelegt ist.
**10** | **Alle erstellen** | Legt alle erkannten Geräte in Symcon als Objekte an.<br> **Wichtig: Der Konfigurator legt alle Objekte unter "System" an die im friendly_name vorgegebene MQTT-Struktur wird dabei von Symcon nicht übernommen.** <br> Beim anlegen erhalten die Objekte automatisch den friendly_name als Objekt-Namen ![Objekt Name](/docs/pictures/konfigurator_Objektname.jpg)
**11** | **Erstellen** | Hiermit lassen sich einzelne Devices als Objekte in Symcon anlegen. Auch hier wird die MQTT-Struktur NICHT übernommen. Und der friendly_name aus Z2M wird zum Objekt-Namen.
**12** | **Aktualisieren** | Aktualisiert die Device-Liste im Konfigurator. Dies ist sinnvoll, wenn neue Devices an Z2M angelernt worden sind. <br> **WICHTIG: Es kann manchmal notwendig sein, die Device-Liste zweimal zu aktualisieren, da nicht alle neu angelernten Devices gleich beim ersten mal mit gesendet werden.**
**13** | **Filter** | Hier lässt sich die Device-Liste nach bestimmten Schlagworten filtern. Es wird dabei auf alle Spalten Rücksicht genommen. Wenn ich Also "Osram" eingebe wird in allen Spalten das Wort "Osram" gesucht und bei Vorhandensein werden die betreffenden Devices in der Liste angezeigt: ![Osram](/docs/pictures/konfigurator_osram.jpg)<br>Gibt man z.B. "01Mini" ein werden alle Devices mit der ModelID gezeigt:<br> ![01Mini](/docs/pictures/konfigurator_miniZB.jpg)
**14** | **Mülleimer** | Hier können angelegte Objekte wieder gelöscht werden
**15** | **MQTT Base Topic** | Das Topic, welches Ihr in der configuration.yaml hinterlegt habt. <br> **WICHTIG: Bei Anlage des Konfigurators wird automatisch "zigbee2mqtt" eingetragen. Solltet Ihr ein anderes Topic gewählt haben, müsst Ihr dies hier anpassen.**

## 4. Statusvariablen

Dieses Modul erzeugt keine Statusvariablen.  

## 5. PHP-Funktionsreferenz

Keine Funktionen verfügbar.  

## 6. Aktionen

Keine Aktionen verfügbar.

## 7. Anhang

### 1. Changelog

[Changelog der Library](../README.md#5-changelog)

### 2. Spenden

Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=EK4JRP87XLSHW" target="_blank"><img src="https://www.paypalobjects.com/de_DE/DE/i/btn/btn_donate_LG.gif" border="0" /></a> <a href="https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share" target="_blank">Amazon Wunschzettel</a>

### 3. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)