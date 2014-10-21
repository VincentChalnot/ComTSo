<?php

namespace ComTSo\ForumBundle\Lib;

class Utils
{
    const CHARSET = 'UTF-8';
    const LOCALE = 'fr_FR.utf8';

    /**
     * Slugify a string, transform all special chars to the delimiter and lower-
     * case the result. Try to replace all accuentuated chars to their ASCII
     * equivalent if Iconv exists and if the locale is set correctly
     * @param  string $string
     * @param  char   $delimiter
     * @return string
     */
    public static function slugify($string, $delimiter = '-')
    {
        $string = self::replaceControlChars((string) $string, $delimiter); // replace non letter or digits by the delimiter
        $string = strtolower(self::asciiFormat(trim($string, $delimiter))); //trim, transliterate to ascii and to lowercase
        $string = preg_replace('~[^'.preg_quote($delimiter).'\w]+~', '', $string); // remove unwanted characters
        if (empty($string)) {
            return "n{$delimiter}a";
        }

        return $string;
    }

    public static function asciiFormat($string)
    {
        if (function_exists('iconv')) {
            $lc_all = setlocale(LC_ALL, 0);
            setlocale(LC_ALL, self::LOCALE);
            $string = iconv(self::CHARSET, 'ASCII//TRANSLIT', (string) $string); // transliterate
            setlocale(LC_ALL, $lc_all);
        }

        return $string;
    }

    /**
     * Replace all control characters (meaning not letters neither numbers)
     * @param  type $subject
     * @param  type $replacement
     * @return type
     */
    public static function replaceControlChars($subject, $replacement = '')
    {
        return preg_replace('~[^\\pL\d]+~u', $replacement, (string) $subject);
    }

    /**
     * Camelize a string
     * @param  type $string
     * @return type
     */
    public static function camelize($string)
    {
        $string = ucwords(self::replaceControlChars((string) $string, ' '));
        $string = str_replace(' ', '', $string);

        return $string;
    }

    /**
     * Convert HTML to text, optionnaly summarize to specified lenght
     * @param  string $html
     * @param  int    $cut
     * @return string
     */
    public static function convertToText($html, $len = null)
    {
        if ($len == null) {
            return html_entity_decode(strip_tags((string) $html));
        }

        return self::summarize(html_entity_decode(strip_tags($html)), $len);
    }

    /**
     * This function tries to gracefully shorten titles and short strings to
     * the specified number of characters.
     * @param  string $string
     * @param  int    $len
     * @return string
     */
    public static function shorten($string, $len = 40)
    {
        $tmp = explode(',', (string) $string);
        $string = trim($tmp[0]);
        if (strlen($string) > $len) {
            $tmp = substr($string, 0, $len + 1);
            $cut = strrpos($tmp, ' ');
            $tmp = substr($tmp, 0, $cut);
            if ($tmp == '') {
                $tmp = substr($string, 0, $len);
            }

            return trim($tmp).'…';
        }

        return $string;
    }

    /**
     * This function tries to gracefully shorten a long text to the specified
     * number of characters.
     * @param  string $string
     * @param  int    $len
     * @return int
     */
    public static function summarize($string, $len = 300)
    {
        if (strlen((string) $string) > $len) {
            $tmp = substr($string, 0, $len + 1);
            $cut = strrpos($tmp, '.', -1);
            $cut2 = strrpos($tmp, ',', -1);
            if ($cut2 > $cut) {
                $cut = $cut2;
            }
            if ($cut < strlen($string) * 0.7) {
                $cut = strrpos($tmp, ' ', -1);
            }
            $tmp = substr($tmp, 0, $cut);
            if ($tmp == '') {
                $tmp = substr($string, 0, $len);
            }

            return trim($tmp).'…';
        }

        return $string;
    }

    /**
     * Secure input to prevent XSS attacks
     * This is just converting sensitive characters to their HTML equivalents
     * @param  string $string
     * @return string
     */
    public static function secureDisplay($string)
    {
        return htmlspecialchars((string) $string, ENT_COMPAT, self::CHARSET);
    }

    /**
     * Autoformat date and/or time inside string from international convention
     * dd/mm/YY(YY) of dd-mm-YY(YY) to ISO date(time)
     * @param string $value
     */
    public static function dateTimeFormat($value)
    {
        try {
            $value = (string) $value;
        } catch (Exception $e) {
            return $value;
        }
        $date_regexp = '([0-3]?[0-9])[\/\-]([0-1][0-9])[\/\-]([1-2][0-9])?(\d\d)'; // Date
        $time_regexp = '([0-2]?[0-9])[:h]?([0-6][0-9])[:m]?([0-6][0-9])?s?'; //Time
        $zone_regexp = '([\+\-][0-1]?[0-9])([:h]?([0-6][0-9]))'; //TimeZone
        if (preg_match("/^[^\d]*({$date_regexp}[^\d]*)?({$time_regexp})?({$zone_regexp})?[^\d]*$/", $value, $matches)) {
            $date = isset($matches[5]) ? $matches[5] : null;
            if ($date) {
                $date = ($matches[4] ? $matches[4] : ($date > 30 ? '19' : '20')).$date;
                $date .= '-'.$matches[3];
                $date .= '-'.$matches[2];
            }

            $time = isset($matches[7]) ? $matches[7] : null;
            if ($time) {
                $time .= ':'.$matches[8];
                if (isset($matches[9]) && $matches[9]) {
                    $time .= ':'.$matches[9];
                }
                if (isset($matches[11]) && $matches[11]) {
                    $time .= $matches[11];
                    $time .= ':'.((isset($matches[13]) && $matches[13]) ? $matches[13] : '00');
                }
            }

            if ($date || $time) {
                $value = '';
                if ($date) {
                    $value = $date;
                }
                if ($date && $time) {
                    $value .= ' ';
                }
                if ($time) {
                    $value .= $time;
                }
            }
        }

        return $value;
    }

    /**
     * Format money from floating number to string like 2 000,00 € with
     * insecable spaces.
     * You can change the unit in app.yml
     * @param  double $price
     * @return string
     */
    public static function moneyFormat($price)
    {
        return str_replace(' ', utf8_encode(chr(160)), number_format(self::numberParse($price), 2, ',', ' ').' '.sfConfig::get('app_surface_money_unit', '€'));
    }

    /**
     * Format a string to match a number form
     * @param  string $value
     * @return double
     */
    public static function numberParse($value)
    {
        $value = preg_replace('/[^\d,\.\+\-eE]+/', '', (string) $value);
        if (strpos($value, ',') && strpos($value, '.')) {
            $value = str_replace(',', '', $value);
        }
        $value = (string) str_replace(',', '.', $value);
        if ((!$value && $value !== '0') || $value == 'e' || $value == 'E') {
            return null;
        }

        return $value * 1;
    }

    /**
     * Format a string to match a number form
     * Warning ! Maximum floating point precision is limited to 12
     * @param  mixed  $value number
     * @return string
     */
    public static function numberFormat($value, $decimals = null, $point = '.', $separator = '', $unit = null)
    {
        if ($value === null || $value === '') {
            return '';
        }
        $nolimit = false;
        if ($decimals === null) {
            //précision max fixée à 1E-16 dans PHP
            $decimals = 12;
            $nolimit = true;
        }
        $value = number_format(self::numberParse($value), $decimals, $point, $separator);
        if ($nolimit) {
            $value = rtrim(rtrim($value, '0'), $point);
        }

        return str_replace(' ', utf8_encode(chr(160)), $value.($unit ? ' '.$unit : ''));
    }

    /**
     * Use this function to display a filesize in a human readable form.
     * @param  in     $size number of octets
     * @return string
     */
    public static function filesizeFormat($size, $decimals = 1)
    {
        $size = self::numberParse($size);
        $units = array('octets', 'Ko', 'Mo', 'Go', 'To', 'Po');
        $i = 0;
        while ($size > 1024) {
            $size = $size / 1024;
            $i++;
        }
        if ($size >= 100) {
            $decimals--;
        }

        return self::numberFormat($size, $decimals, '.', '', $units[$i]);
    }

    public static function upperCaseFirst($string)
    {
        $strlen = mb_strlen($string, self::CHARSET);
        $firstChar = mb_substr($string, 0, 1, self::CHARSET);
        $then = mb_substr($string, 1, $strlen - 1, self::CHARSET);

        return mb_strtoupper($firstChar, self::CHARSET).$then;
    }

}
