<?php
/**
 * Plugin Name: حساب‌یار مودیان
 * Description: حسابداری سبک فروش (اشخاص، کالاها، فاکتور فروش و برگشت از فروش) با ارسال مستقیم صورتحساب به سامانه مودیان و اتصال به ووکامرس.
 * Version: 1.1.2
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Hesabyar
 * License: MIT
 * Text Domain: hesabyar
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HY_VERSION', '1.1.2');
define('HY_FILE', __FILE__);
define('HY_DIR', plugin_dir_path(__FILE__));
define('HY_URL', plugin_dir_url(__FILE__));

require_once HY_DIR . 'includes/moadian/class-hy-moadian-util.php';
require_once HY_DIR . 'includes/moadian/class-hy-moadian-transport.php';
require_once HY_DIR . 'includes/moadian/class-hy-moadian-selftsp.php';
require_once HY_DIR . 'includes/moadian/class-hy-moadian-v2.php';
require_once HY_DIR . 'includes/class-hy-jalali.php';
require_once HY_DIR . 'includes/class-hy-settings.php';
require_once HY_DIR . 'includes/class-hy-db.php';
require_once HY_DIR . 'includes/class-hy-models.php';
require_once HY_DIR . 'includes/class-hy-moadian-service.php';
require_once HY_DIR . 'includes/class-hy-woocommerce.php';
require_once HY_DIR . 'includes/admin/class-hy-admin.php';

// شناسه‌یاب: automatic general stuff IDs for products (added in 1.1.0)
if (!class_exists('HYS_Classifier')) {
    define('HYS_VERSION', HY_VERSION);
    define('HYS_DATA_VERSION', '1405-07-25');
    define('HYS_DIR', HY_DIR);
    define('HYS_URL', HY_URL);
    require_once HY_DIR . 'includes/stuffid/class-hys-data.php';
    require_once HY_DIR . 'includes/stuffid/class-hys-classifier.php';
    require_once HY_DIR . 'includes/stuffid/class-hys-product.php';
    require_once HY_DIR . 'includes/stuffid/class-hys-admin.php';
}

register_activation_hook(__FILE__, 'hy_activate');
register_deactivation_hook(__FILE__, 'hy_deactivate');

function hy_activate() {
    if (!extension_loaded('openssl')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('افزونه حساب‌یار به افزونه OpenSSL در PHP نیاز دارد.');
    }
    HY_DB::install();
    if (!wp_next_scheduled('hy_moadian_inquiry')) {
        wp_schedule_event(time() + 300, 'hourly', 'hy_moadian_inquiry');
    }
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('hy_manage');
    }
    $shop_manager = get_role('shop_manager');
    if ($shop_manager) {
        $shop_manager->add_cap('hy_manage');
    }
}

function hy_deactivate() {
    wp_clear_scheduled_hook('hy_moadian_inquiry');
}

add_action('plugins_loaded', function () {
    if (get_option('hy_db_version') !== HY_DB::VERSION) {
        HY_DB::install();
    }
    HY_Admin::init();
    HY_WooCommerce::init();
    if (class_exists('HYS_Data')) {
        // also covers "replace with uploaded version", where the activation hook does not run
        if (is_admin() && get_option('hys_data_version') !== HYS_DATA_VERSION) {
            HYS_Data::install();
        }
        if (class_exists('WooCommerce')) {
            HYS_Product::init();
        }
        HYS_Admin::init();
    }
});

add_action('hy_moadian_inquiry', array('HY_Moadian_Service', 'cron_inquiry'));

// Declare compatibility with WooCommerce High-Performance Order Storage.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
