<?php

declare(strict_types=1);

namespace Zigbee2MQTT;

trait ColorHelper
{
    /**
     * RGBToHSL
     *
     * @param  int $r red
     * @param  int $g green
     * @param  int $b blue
     * @return array index mit keys hue, saturation, lightness
     */
    protected function RGBToHSL($r, $g, $b)
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
     * HSLToRGB
     *
     * @param  int $h hue
     * @param  int $s saturation
     * @param  int $l lightness
     * @return array index r für red, index g für green, index b für blue
     */
    protected function HSLToRGB($h, $s, $l)
    {
        $h /= 360;
        $s /= 100;
        $l /= 100;

        if ($s == 0) {
            $r = $g = $b = $l * 255; // Monochrome Farben
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = $this->hueToRGB($p, $q, $h + 1 / 3);
            $g = $this->hueToRGB($p, $q, $h);
            $b = $this->hueToRGB($p, $q, $h - 1 / 3);
        }

        $RGB = [
            'r' => round($r * 255),
            'g' => round($g * 255),
            'b' => round($b * 255),
        ];

        // Debug-Ausgabe für die berechneten RGB-Werte
        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: HSL to RGB Conversion', 'H: ' . ($h * 360) . ', S: ' . ($s * 100) . ', L: ' . ($l * 100) . ' => R: ' . $RGB['r'] . ', G: ' . $RGB['g'] . ', B: ' . $RGB['b'], 0);

        return $RGB;
    }

    /**
     * HexToRGB
     * @Burki24 Funktionsbezeichnung ist irreführend, da $Value int und kein Hex-String ist.
     * @param  int $value Ist kein Hex (String) sondern ein int,
     * @return array index 0 int red, index 1 int green, index 2 int blue
     */
    protected function HexToRGB($value)
    {
        $RGB = [];
        $RGB[0] = (($value >> 16) & 0xFF);
        $RGB[1] = (($value >> 8) & 0xFF);
        $RGB[2] = ($value & 0xFF);
        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: HexToRGB', 'R: ' . $RGB[0] . ' G: ' . $RGB[1] . ' B: ' . $RGB[2], 0);
        return $RGB;
    }

    /**
     * HSToRGB
     * @Burki24 Warum ist brightness fest auf 1 (100%)? HSVToRGB ist quasi identisch
     * @param  int $hue
     * @param  int $saturation
     * @return string HTML-Farbe (warum nicht wieder ein int oder array?)
     */
    protected function HSToRGB($hue, $saturation)
    {
        $hue /= 360;
        $saturation /= 100;
        $brightness = 1;
        if ($saturation == 0) {
            $r = $g = $b = $brightness;
        } else {
            $hue *= 6;
            $i = floor($hue);
            $f = $hue - $i;
            $p = $brightness * (1 - $saturation);
            $q = $brightness * (1 - $saturation * $f);
            $t = $brightness * (1 - $saturation * (1 - $f));
            switch ($i) {
                case 0: $r = $brightness;
                    $g = $t;
                    $b = $p;
                    break;
                case 1: $r = $q;
                    $g = $brightness;
                    $b = $p;
                    break;
                case 2: $r = $p;
                    $g = $brightness;
                    $b = $t;
                    break;
                case 3: $r = $p;
                    $g = $q;
                    $b = $brightness;
                    break;
                case 4: $r = $t;
                    $g = $p;
                    $b = $brightness;
                    break;
                default: $r = $brightness;
                    $g = $p;
                    $b = $q;
                    break;
            }
        }
        $r = round($r * 255);
        $g = round($g * 255);
        $b = round($b * 255);
        $colorHS = sprintf('#%02x%02x%02x', $r, $g, $b);
        return $colorHS;
    }

    /**
     * HSVToRGB
     *
     * @param  int $hue
     * @param  int $saturation
     * @param  int $value oder brightness
     * @return string HTML-Farbe (warum nicht wieder ein int oder array?)
     */
    protected function HSVToRGB($hue, $saturation, $value)
    {
        $hue /= 360;
        $saturation /= 100;
        $value /= 100;
        $i = floor($hue * 6);
        $f = $hue * 6 - $i;
        $p = $value * (1 - $saturation);
        $q = $value * (1 - $f * $saturation);
        $t = $value * (1 - (1 - $f) * $saturation);
        switch ($i % 6) {
            case 0: $r = $value;
                $g = $t;
                $b = $p;
                break;
            case 1: $r = $q;
                $g = $value;
                $b = $p;
                break;
            case 2: $r = $p;
                $g = $value;
                $b = $t;
                break;
            case 3: $r = $p;
                $g = $q;
                $b = $value;
                break;
            case 4: $r = $t;
                $g = $p;
                $b = $value;
                break;
            case 5: $r = $value;
                $g = $p;
                $b = $q;
                break;
        }
        $r = round($r * 255);
        $g = round($g * 255);
        $b = round($b * 255);
        $colorRGB = sprintf('#%02x%02x%02x', $r, $g, $b);
        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: HSVToRGB', 'R: ' . $r . ' G: ' . $g . ' B: ' . $b, 0);
        $this->SendDebug(__FUNCTION__ . ' :: ' . __LINE__ . ' :: HSVToRGB', 'RGB ' . $colorRGB, 0);

        return $colorRGB;
    }

    /**
     * RGBToHSV
     *
     * @param  mixed $R red
     * @param  mixed $G green
     * @param  mixed $B blue
     * @return array index mit keys hue, saturation, value (brightness)
     */
    protected function RGBToHSV($R, $G, $B)
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
    protected function RGBToHSB($R, $G, $B)
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
     * xyToHEX
     *
     * @param  float $x
     * @param  float $y
     * @param  int $bri brightness
     * @return int Integer-Wert der Farbe
     */
    protected function xyToHEX($x, $y, $bri = 255)
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
    protected function RGBToXy($RGB)
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
     * hueToRGB
     *
     * @Schnittcher Hilfe :)
     * @Burki24  Hilfe :)
     * @param  mixed $p ???
     * @param  mixed $q ???
     * @param  mixed $t ???
     * @return float
     */
    private function hueToRGB($p, $q, $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }
        return $p;
    }

    /**
     * Konvertiert einen HEX-Farbwert in XY-Farbkoordinaten.
     *
     * @param int $hex Der HEX-Farbwert.
     * @return array Ein Array mit den Schlüsseln 'x' und 'y'.
     */
    function HexToXY(int $hex): array
    {
        // HEX in RGB umwandeln
        $r = (($hex >> 16) & 0xFF) / 255.0;
        $g = (($hex >> 8) & 0xFF) / 255.0;
        $b = ($hex & 0xFF) / 255.0;

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

        // Rückgabe der xy-Koordinaten
        return ['x' => $x, 'y' => $y];
    }

    /**
     * Konvertiert Kelvin in Mired
     *
     * @param  int $value Kelvin-Wert
     * @return int Mired-Wert
     */
    protected function convertKelvinToMired($value): int
    {
        if ($value >= 1000) {
            $miredValue = intval(round(1000000 / $value, 0));
            $this->SendDebug(__FUNCTION__, 'Kelvin zu Mired konvertiert: ' . $miredValue, 0);
        } else {
            $miredValue = intval($value);
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
    protected function convertMiredToKelvin($value)
    {
        if ($value > 0) {
            $kelvinValue = intval(round(1000000 / $value, 0));
            $this->SendDebug(__FUNCTION__, 'Mired zu Kelvin konvertiert: ' . $kelvinValue, 0);
        } else {
            $kelvinValue = 0;
            $this->SendDebug(__FUNCTION__, 'Ungültiger Mired-Wert: ' . $value, 0);
        }
        return $kelvinValue;
    }

    protected function normalizeValueToRange($value, $oldMin, $oldMax, $newMin = 0, $newMax = 100)
    {
        // Vermeidung Division durch 0
        if ($oldMin == $oldMax) return $newMin;

        // Lineare Transformation
        $normalizedValue = (($value - $oldMin) * ($newMax - $newMin)) / ($oldMax - $oldMin) + $newMin;

        // Auf ganze Zahl runden
        return max($newMin, min($newMax, round($normalizedValue)));
    }

    /**
 * Gibt den maximalen Helligkeitswert aus der Konfiguration zurück.
 *
 * Diese Methode liest die gespeicherte Helligkeitskonfiguration aus dem Buffer und
 * extrahiert den maximalen Helligkeitswert. Falls keine Konfiguration vorhanden ist
 * oder der Wert nicht gesetzt wurde, wird der Standardwert 255 zurückgegeben.
 *
 * @return int Der maximale Helligkeitswert (Standard: 255)
 */
    private function getBrightnessMaxValue(): int
    {
        $config = json_decode($this->GetBuffer('brightnessConfig'), true);
        return isset($config['max']) ? (int)$config['max'] : 255;
    }
}
