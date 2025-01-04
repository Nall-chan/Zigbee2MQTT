<?php

declare(strict_types=1);

namespace Zigbee2MQTT;

trait ColorHelper
{
    /** @var string Name des Buffers für Config der Helligkeit */
    protected const BUFFER_BRIGHTNESS_CONFIG = 'brightnessConfig';

    /**
     * RGBToHSL
     *
     * @param  int $r red
     * @param  int $g green
     * @param  int $b blue
     * @return array index mit keys hue, saturation, lightness
     */
    protected function RGBToHSL(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $hue = $saturation = $lightness = ($max + $min) / 2;

        if ($max == $min) {
            $hue = $saturation = 0; // Monochrome Farben
        } else {
            $delta = $max - $min;
            $saturation = $lightness > 0.5 ? $delta / (2 - $max - $min) : $delta / ($max + $min);

            switch ($max) {
                case $r:
                    $hue = ($g - $b) / $delta + ($g < $b ? 6 : 0);
                    break;
                case $g:
                    $hue = ($b - $r) / $delta + 2;
                    break;
                case $b:
                    $hue = ($r - $g) / $delta + 4;
                    break;
            }
            $hue /= 6;
        }

        return [
            'hue'        => round($hue * 360, 2),
            'saturation' => round($saturation * 100, 2),
            'lightness'  => round($lightness * 100, 2),
        ];
    }

    /**
     * IntToRGB
     * @param  int $value 32Bit Farbwert 0xRRGGBB,
     * @return array index 0 int red, index 1 int green, index 2 int blue
     */
    protected function IntToRGB(int $value): array
    {
        $RGB = [];
        $RGB[0] = (($value >> 16) & 0xFF);
        $RGB[1] = (($value >> 8) & 0xFF);
        $RGB[2] = ($value & 0xFF);
        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: HexToRGB', 'R: ' . $RGB[0] . ' G: ' . $RGB[1] . ' B: ' . $RGB[2], 0);
        return $RGB;
    }

    /**
     * RGBToHSV
     *
     * @param  int $R red
     * @param  int $G green
     * @param  int $B blue
     * @return array index mit keys hue, saturation, value (brightness)
     */
    protected function RGBToHSV(int $R, int $G, int $B): array
    {
        $R /= 255;
        $G /= 255;
        $B /= 255;
        $max = max($R, $G, $B);
        $min = min($R, $G, $B);
        $delta = $max - $min;
        $value = $max * 100;
        $saturation = ($max == 0) ? 0 : ($delta / $max) * 100;
        $hue = 0;
        if ($delta != 0) {
            if ($max == $R) {
                $hue = 60 * (($G - $B) / $delta);
            } elseif ($max == $G) {
                $hue = 60 * (($B - $R) / $delta) + 120;
            } elseif ($max == $B) {
                $hue = 60 * (($R - $G) / $delta) + 240;
            }
            if ($hue < 0) {
                $hue += 360;
            }
        }

        return [
            'hue'        => $hue,
            'saturation' => $saturation,
            'value'      => $value
        ];
    }

    /**
     * RGBToHSB
     *
     * @param  int $R red
     * @param  int $G green
     * @param  int $B blue
     * @return array index mit keys hue, saturation, brightness
     */
    protected function RGBToHSB(int $R, int $G, int $B): array
    {
        $R /= 255;
        $G /= 255;
        $B /= 255;
        $max = max($R, $G, $B);
        $min = min($R, $G, $B);
        $delta = $max - $min;
        $brightness = $max * 100;
        $saturation = ($max == 0) ? 0 : ($delta / $max) * 100;
        $hue = 0;
        if ($delta != 0) {
            if ($max == $R) {
                $hue = 60 * (($G - $B) / $delta);
            } elseif ($max == $G) {
                $hue = 60 * (($B - $R) / $delta) + 120;
            } elseif ($max == $B) {
                $hue = 60 * (($R - $G) / $delta) + 240;
            }
            if ($hue < 0) {
                $hue += 360;
            }
        }
        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: Output HSB', "Hue: $hue, Saturation: $saturation, Brightness: $brightness", 0);
        return ['hue' => $hue, 'saturation' => $saturation, 'brightness' => $brightness];
    }

    /**
     * xyToInt
     *
     * @param  float $x
     * @param  float $y
     * @param  int $bri brightness
     * @return int Integer-Wert der Farbe
     */
    protected function xyToInt(float $x, float $y, int $bri = 254): int
    {
        // Normalisierung der Brightness (0-1)
        $Y = $bri / 255;

        // Berechnung der XYZ-Werte
        if ($y == 0) {
            $X = 0;
            $Z = 0;
        } else {
            $X = ($Y / $y) * $x;
            $Z = ($Y / $y) * (1 - $x - $y);
        }

        // Präzisere XYZ zu RGB Matrix (sRGB D65)
        $r = $X * 3.2406 - $Y * 1.5372 - $Z * 0.4986;
        $g = -$X * 0.9689 + $Y * 1.8758 + $Z * 0.0415;
        $b = $X * 0.0557 - $Y * 0.2040 + $Z * 1.0570;

        // Korrekte Gamma-Korrektur für jeden Kanal
        $r = $r <= 0.0031308 ? 12.92 * $r : (1.0 + 0.055) * pow($r, (1.0 / 2.4)) - 0.055;
        $g = $g <= 0.0031308 ? 12.92 * $g : (1.0 + 0.055) * pow($g, (1.0 / 2.4)) - 0.055;
        $b = $b <= 0.0031308 ? 12.92 * $b : (1.0 + 0.055) * pow($b, (1.0 / 2.4)) - 0.055; // Korrigiert von $g zu $b

        // Debug für Zwischenwerte
        $this->SendDebug(__FUNCTION__ . ' :: Pre-Scale', "R: $r G: $g B: $b", 0);

        // RGB Werte auf 0-255 skalieren und begrenzen
        $r = max(0, min(255, round($r * 255)));
        $g = max(0, min(255, round($g * 255)));
        $b = max(0, min(255, round($b * 255)));

        $this->SendDebug(__FUNCTION__ . ' :: RGB', "R: $r G: $g B: $b", 0);

        // Integer-Wert berechnen
        $color = ($r << 16) | ($g << 8) | $b;
        $this->SendDebug(__FUNCTION__ . ' :: colorINT', $color, 0);

        return $color;
    }

    /**
     * RGBToXy
     *
     * @param  array $RGB mit index 0 int red, index 1 int green, index 2 int blue
     * @return array mit index x, y, bri
     */
    protected function RGBToXy(array $RGB): array
    {
        $r = $RGB[0] / 255;
        $g = $RGB[1] / 255;
        $b = $RGB[2] / 255;

        // RGB in Xy-Farbraum konvertieren
        $r = ($r > 0.04045 ? pow(($r + 0.055) / 1.055, 2.4) : ($r / 12.92));
        $g = ($g > 0.04045 ? pow(($g + 0.055) / 1.055, 2.4) : ($g / 12.92));
        $b = ($b > 0.04045 ? pow(($b + 0.055) / 1.055, 2.4) : ($b / 12.92));

        $X = $r * 0.664511 + $g * 0.154324 + $b * 0.162028;
        $Y = $r * 0.283881 + $g * 0.668433 + $b * 0.047685;
        $Z = $r * 0.000088 + $g * 0.072310 + $b * 0.986039;

        if (($X + $Y + $Z) == 0) {
            $x = 0;
            $y = 0;
        } else {
            $x = round($X / ($X + $Y + $Z), 4);
            $y = round($Y / ($X + $Y + $Z), 4);
        }
        $bri = round($Y * 254);
        if ($bri > 254) {
            $bri = 254;
        }

        $cie['x'] = $x;
        $cie['y'] = $y;
        $cie['bri'] = $bri;
        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: RGBToXYX', json_encode($cie), 0);

        return $cie;
    }

    /**
     * IntToXY
     *
     * Konvertiert einen Integer-Farbwert in XY-Farbkoordinaten mit Helligkeit.
     *
     * @param int $value 32Bit Farbwert 0xRRGGBB
     * @param int $brightness Optional: Helligkeit 0-100%
     * @return array Ein Array mit den Schlüsseln 'x', 'y' und 'brightness'
     */
    protected function IntToXY(int $value, int $brightness = 100): array
    {
        // HEX in RGB umwandeln
        $r = (($value >> 16) & 0xFF) / 255.0;
        $g = (($value >> 8) & 0xFF) / 255.0;
        $b = ($value & 0xFF) / 255.0;

        // Gamma-Korrektur
        $r = ($r > 0.04045) ? pow(($r + 0.055) / (1.0 + 0.055), 2.4) : ($r / 12.92);
        $g = ($g > 0.04045) ? pow(($g + 0.055) / (1.0 + 0.055), 2.4) : ($g / 12.92);
        $b = ($b > 0.04045) ? pow(($b + 0.055) / (1.0 + 0.055), 2.4) : ($b / 12.92);

        // RGB in XYZ umwandeln
        $X = $r * 0.4124 + $g * 0.3576 + $b * 0.1805;
        $Y = $r * 0.2126 + $g * 0.7152 + $b * 0.0722;
        $Z = $r * 0.0193 + $g * 0.1192 + $b * 0.9505;

        // XYZ in xy umwandeln
        $x = $X / ($X + $Y + $Z);
        $y = $Y / ($X + $Y + $Z);

        // Helligkeit normalisieren (0-100% -> 0-1)
        $brightness = max(0, min(100, $brightness)) / 100;

        // Rückgabe der xy-Koordinaten und Helligkeit
        return [
            'x' => $x,
            'y' => $y,
            'brightness' => $brightness
        ];
    }

    /**
     * Konvertiert Kelvin in Mired
     *
     * @param  int $value Kelvin-Wert
     * @return int Mired-Wert
     */
    protected function convertKelvinToMired(int $value): int
    {
        if ($value >= 1000) {
            $miredValue = intdiv(1000000, $value);
            $this->SendDebug(__FUNCTION__, 'Kelvin zu Mired konvertiert: ' . $miredValue, 0);
        } else {
            $miredValue = (int) $value;
            $this->SendDebug(__FUNCTION__, 'Wert unter 1000, Keine Konvertierung: ' . $miredValue, 0);
        }
        return $miredValue;
    }

    /**
     * Konvertiert Mired in Kelvin
     *
     * @param  int $value Mired-Wert
     * @return int Kelvin-Wert
     */
    protected function convertMiredToKelvin(int $value): int
    {
        if ($value > 0) {
            $kelvinValue = intdiv(1000000, $value);
            $this->SendDebug(__FUNCTION__, 'Mired zu Kelvin konvertiert: ' . $kelvinValue, 0);
        } else {
            $kelvinValue = 0;
            $this->SendDebug(__FUNCTION__, 'Ungültiger Mired-Wert: ' . $value, 0);
        }
        return $kelvinValue;
    }

    /**
     * normalizeValueToRange
     *
     * Rechnet die Helligkeit zwischen dem absoluten Gerätewert und relativem Prozentwert um.
     *
     * @param  int $value Helligkeit
     * @param  bool $toDevice `TRUE` wenn Richtung Gerät gerechnet wird, `FALSE` in Richtung Prozent.
     * @return int Helligkeit
     */
    protected function normalizeValueToRange(int $value, bool $toDevice = true): int
    {
        $oldMin = $this->getBrightnessValue('min');
        $oldMax = $this->getBrightnessValue('max');

        if ($toDevice) {
            // Prozent -> Gerätewert
            $this->SendDebug(__FUNCTION__, sprintf(
                'Converting %d%% to device value (range %d-%d)',
                $value,
                $oldMin,
                $oldMax
            ), 0);

            $value = max(0, min(100, $value));
            // Konvertierung von Prozent (0-100) in Gerätewert (oldMin-oldMax)
            $result = (int) (($value * ($oldMax - $oldMin) / 100) + $oldMin);
        } else {
            // Gerätewert -> Prozent
            $this->SendDebug(__FUNCTION__, sprintf(
                'Converting device value %d (range %d-%d) to percent',
                $value,
                $oldMin,
                $oldMax
            ), 0);

            $value = max($oldMin, min($oldMax, $value));
            $result = intdiv(($value - $oldMin) * 100, $oldMax - $oldMin);
        }

        $this->SendDebug(__FUNCTION__, sprintf(
            'Result: %d (%s)',
            $result,
            $toDevice ? 'device value' : 'percent'
        ), 0);
        return $result;
    }

    /**
     * getBrightnessValue
     *
     * Gibt den maximalen und minimalen Helligkeitswert aus der Konfiguration zurück.
     *
     * Diese Methode liest die gespeicherte Helligkeitskonfiguration aus dem Buffer und
     * extrahiert die max/min Helligkeitswerte. Falls keine Konfiguration vorhanden ist
     * oder die Werte nicht gesetzt wurden, wird der Standardwert 0/254 zurückgegeben.
     *
     * @param  string $type min/max Typ welcher gelesen werden soll
     * @return int Die min/max Helligkeitswerte (Standard: 0/254)
     */
    private function getBrightnessValue(string $type = 'max'): int
    {
        $config = json_decode($this->GetBuffer(self::BUFFER_BRIGHTNESS_CONFIG), true);
        $this->SendDebug(__FUNCTION__, 'Gelesene Brightness-Config: ' . print_r($config, true), 0);

        $defaults = [
            'min' => 0,
            'max' => 254
        ];

        $value = isset($config[$type]) ? (int) $config[$type] : $defaults[$type];
        $this->SendDebug(__FUNCTION__, sprintf(
            'Brightness %s-Wert: %d (%s)',
            $type,
            $value,
            isset($config[$type]) ? 'konfiguriert' : 'default'
        ), 0);

        return $value;
    }
}
