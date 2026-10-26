<?php
/**
 * General stuff IDs table: install, import from the bundled file, lookups.
 */

if (!defined('ABSPATH')) {
    exit;
}

class HYS_Settings {
    const OPTION = 'hys_settings';

    public static function defaults() {
        return array(
            'default_origin' => 'D',   // origin when the brand is unknown
            'auto_fill' => 1,          // fill empty stuff IDs when a product is saved
            'fill_vat' => 1,           // also fill an empty VAT rate
        );
    }

    public static function get($key) {
        $all = wp_parse_args((array) get_option(self::OPTION, array()), self::defaults());
        return isset($all[$key]) ? $all[$key] : null;
    }

    public static function save(array $in) {
        $all = self::defaults();
        $all['default_origin'] = (isset($in['default_origin']) && $in['default_origin'] === 'I') ? 'I' : 'D';
        $all['auto_fill'] = empty($in['auto_fill']) ? 0 : 1;
        $all['fill_vat'] = empty($in['fill_vat']) ? 0 : 1;
        update_option(self::OPTION, $all, false);
    }
}

class HYS_Data {
    const DB_VERSION = '1';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'hys_general';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        $c = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $t (
  id char(13) NOT NULL,
  origin char(1) NOT NULL DEFAULT 'D',
  vat decimal(5,2) NOT NULL DEFAULT 10.00,
  taxable varchar(20) NOT NULL DEFAULT '',
  expiration varchar(10) NOT NULL DEFAULT '',
  last_edit varchar(10) NOT NULL DEFAULT '',
  descr varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY descr (descr(191))
) $c;");
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
        if ($count === 0 || get_option('hys_data_version') !== HYS_DATA_VERSION) {
            self::import();
        }
        update_option('hys_db_version', self::DB_VERSION, false);
    }

    /** Loads data/general_ids.tsv.gz into the table (replaces existing rows). */
    public static function import() {
        global $wpdb;
        $t = self::table();
        $file = HYS_DIR . 'data/general_ids.tsv.gz';
        $fh = gzopen($file, 'rb');
        if (!$fh) {
            return new WP_Error('hys_data', 'فایل داده شناسه‌های عمومی پیدا نشد.');
        }
        @set_time_limit(300);
        $wpdb->query("TRUNCATE TABLE $t");
        gzgets($fh); // header
        $batch = array();
        $n = 0;
        while (($line = gzgets($fh)) !== false) {
            $f = explode("\t", rtrim($line, "\r\n"));
            if (count($f) < 7 || !preg_match('/^\d{13}$/', $f[0])) {
                continue;
            }
            $batch[] = $wpdb->prepare('(%s,%s,%f,%s,%s,%s,%s)', $f[0], $f[1], (float) $f[2], $f[3], $f[4], $f[5], mb_substr($f[6], 0, 255));
            if (count($batch) >= 400) {
                $wpdb->query("INSERT INTO $t (id,origin,vat,taxable,expiration,last_edit,descr) VALUES " . implode(',', $batch));
                $n += count($batch);
                $batch = array();
            }
        }
        if ($batch) {
            $wpdb->query("INSERT INTO $t (id,origin,vat,taxable,expiration,last_edit,descr) VALUES " . implode(',', $batch));
            $n += count($batch);
        }
        gzclose($fh);
        update_option('hys_data_version', HYS_DATA_VERSION, false);
        return $n;
    }

    public static function count() {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table());
    }

    /** Row for an exact description, preferring the requested origin. */
    public static function find_by_desc($desc, $origin) {
        static $cache = array();
        $k = $origin . '|' . $desc;
        if (isset($cache[$k])) {
            return $cache[$k];
        }
        global $wpdb;
        $t = self::table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $t WHERE descr = %s ORDER BY (origin = %s) DESC LIMIT 1", $desc, $origin
        ), ARRAY_A);
        return $cache[$k] = $row ?: null;
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %s', $id), ARRAY_A);
    }

    /** Keyword search: every word must appear in the description. */
    public static function search($q, $limit = 30) {
        global $wpdb;
        $t = self::table();
        $q = HYS_Classifier::norm($q);
        if (preg_match('/^\d{13}$/', $q)) {
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE id = %s", $q), ARRAY_A);
        }
        $words = array_filter(explode(' ', $q), function ($w) {
            return mb_strlen($w) >= 2;
        });
        if (!$words) {
            return array();
        }
        $where = array();
        $args = array();
        foreach (array_slice($words, 0, 6) as $w) {
            $where[] = 'descr LIKE %s';
            $args[] = '%' . $wpdb->esc_like($w) . '%';
        }
        $args[] = (int) $limit;
        $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $where) . ' ORDER BY CHAR_LENGTH(descr), origin LIMIT %d';
        return $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    }
}
