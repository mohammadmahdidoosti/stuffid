<?php
/**
 * Jalali (شمسی) date helpers. All timestamps are unix seconds; display is in
 * the Asia/Tehran time zone regardless of the WordPress setting, because the
 * invoices are Iranian tax documents.
 *
 * @package Hesabyar
 */

if (!defined('ABSPATH')) {
    exit;
}

class HY_Jalali {

    const TZ = 'Asia/Tehran';

    public static function g2j($gy, $gm, $gd) {
        $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return array($jy, $jm, $jd);
    }

    public static function j2g($jy, $jm, $jd) {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $sal_a = array(0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
        for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) {
            $gd -= $sal_a[$gm];
        }
        return array($gy, $gm, $gd);
    }

    private static function tz() {
        return new DateTimeZone(self::TZ);
    }

    /** unix seconds → "1405/07/23" (and optionally " 16:08") */
    public static function format($timestamp, $with_time = false) {
        if (!$timestamp) {
            return '';
        }
        $dt = (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone(self::tz());
        list($jy, $jm, $jd) = self::g2j((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));
        $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        if ($with_time) {
            $out .= ' ' . $dt->format('H:i');
        }
        return $out;
    }

    public static function time($timestamp) {
        return $timestamp ? (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone(self::tz())->format('H:i') : '';
    }

    /**
     * "1405/07/23" + "16:08" → unix seconds. Accepts Persian/Arabic digits
     * and "-" or "/" separators. Returns null when the input is not a date.
     */
    public static function parse($date, $time = '00:00') {
        $date = self::latin_digits(trim((string) $date));
        $time = self::latin_digits(trim((string) $time));
        if (!preg_match('#^(\d{4})[/\-](\d{1,2})[/\-](\d{1,2})$#', $date, $m)) {
            return null;
        }
        $jy = (int) $m[1];
        $jm = (int) $m[2];
        $jd = (int) $m[3];
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31 || ($jm > 6 && $jd > 30)) {
            return null;
        }
        $h = 0;
        $i = 0;
        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $t)) {
            $h = min(23, (int) $t[1]);
            $i = min(59, (int) $t[2]);
        }
        list($gy, $gm, $gd) = self::j2g($jy, $jm, $jd);
        $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:00', $gy, $gm, $gd, $h, $i), self::tz());
        return $dt->getTimestamp();
    }

    public static $months = array(1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند');

    /** [jy, jm, jd] for a timestamp in Tehran time. */
    public static function parts($timestamp) {
        $dt = (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone(self::tz());
        return self::g2j((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));
    }

    public static function month_name($jm) {
        return self::$months[(int) $jm] ?? '';
    }

    public static function today() {
        return self::format(time());
    }

    public static function latin_digits($s) {
        return strtr((string) $s, array(
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ));
    }

    public static function persian_digits($s) {
        return strtr((string) $s, array('0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'));
    }
}
