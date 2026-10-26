<?php
/**
 * Database schema and small data-access helpers.
 *
 * Money is stored in Rial as BIGINT; quantities as DECIMAL.
 *
 * @package Hesabyar
 */

if (!defined('ABSPATH')) {
    exit;
}

class HY_DB {

    const VERSION = '1.0.0';

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'hy_' . $name;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $persons = self::table('persons');
        $products = self::table('products');
        $invoices = self::table('invoices');
        $items = self::table('invoice_items');
        $logs = self::table('logs');

        dbDelta("CREATE TABLE $persons (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code int(11) NOT NULL DEFAULT 0,
  name varchar(191) NOT NULL DEFAULT '',
  person_type tinyint(4) NOT NULL DEFAULT 1,
  national_id varchar(20) NOT NULL DEFAULT '',
  economic_code varchar(20) NOT NULL DEFAULT '',
  postal_code varchar(20) NOT NULL DEFAULT '',
  phone varchar(40) NOT NULL DEFAULT '',
  mobile varchar(40) NOT NULL DEFAULT '',
  email varchar(191) NOT NULL DEFAULT '',
  address text NULL,
  notes text NULL,
  wc_customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  KEY code (code),
  KEY national_id (national_id),
  KEY wc_customer_id (wc_customer_id)
) $c;");

        dbDelta("CREATE TABLE $products (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code int(11) NOT NULL DEFAULT 0,
  name varchar(255) NOT NULL DEFAULT '',
  sku varchar(100) NOT NULL DEFAULT '',
  unit_title varchar(50) NOT NULL DEFAULT 'عدد',
  unit_code varchar(10) NOT NULL DEFAULT '1627',
  stuff_id varchar(13) NOT NULL DEFAULT '',
  price bigint(20) NOT NULL DEFAULT 0,
  vat_rate decimal(5,2) NOT NULL DEFAULT 10.00,
  is_service tinyint(1) NOT NULL DEFAULT 0,
  wc_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  KEY code (code),
  KEY wc_product_id (wc_product_id)
) $c;");

        dbDelta("CREATE TABLE $invoices (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(10) NOT NULL DEFAULT 'sale',
  number int(11) NOT NULL DEFAULT 0,
  ref_invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  person_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(255) NOT NULL DEFAULT '',
  reference varchar(100) NOT NULL DEFAULT '',
  date_ts bigint(20) NOT NULL DEFAULT 0,
  due_ts bigint(20) NOT NULL DEFAULT 0,
  notes text NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  paid tinyint(1) NOT NULL DEFAULT 0,
  subtotal bigint(20) NOT NULL DEFAULT 0,
  discount bigint(20) NOT NULL DEFAULT 0,
  tax bigint(20) NOT NULL DEFAULT 0,
  total bigint(20) NOT NULL DEFAULT 0,
  wc_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  m_inty tinyint(4) NOT NULL DEFAULT 0,
  m_inp tinyint(4) NOT NULL DEFAULT 1,
  m_ins tinyint(4) NOT NULL DEFAULT 1,
  m_setm tinyint(4) NOT NULL DEFAULT 1,
  m_cap bigint(20) NOT NULL DEFAULT 0,
  m_serial bigint(20) unsigned NULL,
  m_taxid varchar(32) NOT NULL DEFAULT '',
  m_irtaxid varchar(32) NOT NULL DEFAULT '',
  m_status varchar(20) NOT NULL DEFAULT 'unsent',
  m_uid varchar(64) NOT NULL DEFAULT '',
  m_reference varchar(64) NOT NULL DEFAULT '',
  m_message text NULL,
  m_indatim bigint(20) NOT NULL DEFAULT 0,
  m_sent_at datetime NULL,
  m_checked_at datetime NULL,
  m_payload longtext NULL,
  created_at datetime NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY m_serial (m_serial),
  KEY kind_number (kind,number),
  KEY person_id (person_id),
  KEY m_status (m_status),
  KEY wc_order_id (wc_order_id)
) $c;");

        dbDelta("CREATE TABLE $items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sort int(11) NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  description varchar(255) NOT NULL DEFAULT '',
  unit_title varchar(50) NOT NULL DEFAULT '',
  unit_code varchar(10) NOT NULL DEFAULT '',
  stuff_id varchar(13) NOT NULL DEFAULT '',
  qty decimal(18,4) NOT NULL DEFAULT 0,
  unit_price bigint(20) NOT NULL DEFAULT 0,
  discount bigint(20) NOT NULL DEFAULT 0,
  vat_rate decimal(5,2) NOT NULL DEFAULT 0,
  tax bigint(20) NOT NULL DEFAULT 0,
  total bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id)
) $c;");

        dbDelta("CREATE TABLE $logs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  invoice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(40) NOT NULL DEFAULT '',
  status_code int(11) NOT NULL DEFAULT 0,
  request longtext NULL,
  response longtext NULL,
  created_at datetime NULL,
  PRIMARY KEY  (id),
  KEY invoice_id (invoice_id)
) $c;");

        update_option('hy_db_version', self::VERSION, false);
    }

    public static function now() {
        return current_time('mysql', true);
    }

    public static function next_code($table) {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COALESCE(MAX(code),0)+1 FROM ' . self::table($table));
    }

    public static function log($invoice_id, $action, $status_code, $request, $response) {
        global $wpdb;
        $wpdb->insert(self::table('logs'), array(
            'invoice_id' => (int) $invoice_id,
            'action' => $action,
            'status_code' => (int) $status_code,
            'request' => is_string($request) ? $request : wp_json_encode($request),
            'response' => is_string($response) ? $response : wp_json_encode($response),
            'created_at' => self::now(),
        ));
    }
}
