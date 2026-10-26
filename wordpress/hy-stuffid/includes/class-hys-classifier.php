<?php
/**
 * Product name -> general stuff ID (شناسه عمومی).
 * PHP port of rules.py: same normalization, same ordered regex rules.
 */

if (!defined('ABSPATH')) {
    exit;
}

class HYS_Classifier {

    private static $data = null;
    private static $brands = null;

    private static function data() {
        if (self::$data === null) {
            $json = file_get_contents(HYS_DIR . 'data/rules.json');
            self::$data = json_decode($json, true);
        }
        return self::$data;
    }

    private static function brands() {
        if (self::$brands === null) {
            $json = file_get_contents(HYS_DIR . 'data/brands.json');
            self::$brands = json_decode($json, true); // [[alias, "I"|"D"], ...] longest first
        }
        return self::$brands;
    }

    private static function rx($p) {
        return '~(*UCP)' . str_replace('~', '\~', $p) . '~u';
    }

    private static function m($pattern, $s) {
        return preg_match(self::rx($pattern), $s) === 1;
    }

    /** Same as matcher.normalize + whitespace collapse. */
    public static function norm($name) {
        $d = self::data();
        $s = strtr((string) $name, $d['norm']);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('~\s+~u', ' ', $s);
        return trim($s);
    }

    private static function is_metal($n) {
        $d = self::data();
        if (self::m($d['poly'], $n)) {
            return false;
        }
        return self::m($d['metal'], $n);
    }

    private static function is_gravity($n) {
        $d = self::data();
        if (self::m($d['gravity'], $n)) {
            return true;
        }
        if (self::m($d['pressure_sys'], $n)) {
            return false;
        }
        if (self::m('پلیکا|upvc|pvc', $n) && !self::m($d['pressure'], $n)) {
            return true;
        }
        return false;
    }

    private static function call($f, $n) {
        $d = self::data();
        $FL = $d['FL'];
        switch ($f['fn']) {
            case 'fitting':
                $g = self::is_gravity($n);
                $mt = self::is_metal($n);
                if ($g && $mt && !$f['gm']) {
                    $g = false;
                }
                if ($g && !$mt && !$f['gp']) {
                    $g = false;
                }
                $press = $g ? 'غیر تحت فشار' : 'تحت فشار';
                if (!$f['split']) {
                    return $f['item'] . ' انتقال مایع ' . $press . ' ' . $FL;
                }
                return $f['item'] . ' انتقال مایع ' . $press . ' ' . ($mt ? 'فلزی' : 'غیر فلزی') . ' ' . $FL;
            case 'pipe':
                return 'لوله انتقال مایع ' . (self::is_gravity($n) ? 'غیر تحت فشار' : 'تحت فشار') . ' '
                    . (self::is_metal($n) ? 'فلزی' : 'غیر فلزی') . ' جابجایی سیالات عمومی';
            case 'flange':
                return 'فلنج ' . (self::is_gravity($n) ? 'غیر تحت فشار' : 'تحت فشار') . ' ' . $FL;
            case 'cap':
                return 'کلاهک خط لوله مایع ' . (self::is_gravity($n) ? 'غیر تحت فشار' : 'تحت فشار') . ' ' . $FL;
            case 'nut':
                return (self::is_metal($n) ? 'مهره ماسوره انتقال مایع تحت فشار فلزی ' : 'مهره ماسوره انتقال مایع تحت فشار غیرفلزی ') . $FL;
            case 'pneu_fitting':
                foreach ($d['pneu_fit'] as $r) {
                    if (self::m($r['p'], $n)) {
                        return $r['t'];
                    }
                }
                return 'کوپلینگ اتصالات ' . $d['PN'];
        }
        return null;
    }

    /** @return array|null ['desc' => string, 'rule' => string] */
    public static function classify($name) {
        $n = self::norm($name);
        if ($n === '') {
            return null;
        }
        foreach (self::data()['rules'] as $r) {
            if (self::m($r['p'], $n)) {
                $desc = isset($r['f']) ? self::call($r['f'], $n) : $r['t'];
                return array('desc' => $desc, 'rule' => $r['p']);
            }
        }
        return null;
    }

    /** 'I' (imported), 'D' (domestic) or '' (brand unknown). */
    public static function origin($name) {
        $n = ' ' . self::norm($name) . ' ';
        foreach (self::brands() as $b) {
            if (strpos($n, ' ' . $b[0] . ' ') !== false) {
                return $b[1];
            }
        }
        return '';
    }

    /**
     * Full suggestion for a product name.
     * @return array|null id, desc, vat, origin, origin_source, rule, exact_origin
     */
    public static function suggest($name) {
        $c = self::classify($name);
        if (!$c) {
            return null;
        }
        $origin = self::origin($name);
        $source = 'brand';
        if ($origin === '') {
            $origin = HYS_Settings::get('default_origin');
            $source = 'default';
        }
        $row = HYS_Data::find_by_desc($c['desc'], $origin);
        if (!$row) {
            return null;
        }
        return array(
            'id' => $row['id'],
            'desc' => $row['descr'],
            'vat' => (float) $row['vat'],
            'origin' => $row['origin'],
            'origin_source' => $source,
            'exact_origin' => $row['origin'] === $origin,
            'rule' => $c['rule'],
        );
    }
}
