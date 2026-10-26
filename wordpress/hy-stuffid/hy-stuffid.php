<?php
/**
 * Plugin Name: حساب‌یار — شناسه‌یاب
 * Description: برای محصولات ووکامرس، شناسه کالای مودیان (شناسه عمومی) را خودکار پیدا و در فیلد «شناسه کالا مودیان» حساب‌یار ثبت می‌کند.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Hesabyar
 * License: MIT
 * WC requires at least: 7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HYS_VERSION', '1.0.0');
define('HYS_DATA_VERSION', '1405-07-25');
define('HYS_FILE', __FILE__);
define('HYS_DIR', plugin_dir_path(__FILE__));
define('HYS_URL', plugin_dir_url(__FILE__));

require_once HYS_DIR . 'includes/class-hys-data.php';
require_once HYS_DIR . 'includes/class-hys-classifier.php';
require_once HYS_DIR . 'includes/class-hys-product.php';
require_once HYS_DIR . 'includes/class-hys-admin.php';

register_activation_hook(__FILE__, function () {
    if (!function_exists('gzopen') || !function_exists('mb_strtolower')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('افزونه شناسه‌یاب به افزونه‌های zlib و mbstring در PHP نیاز دارد.');
    }
    HYS_Data::install();
});

add_action('plugins_loaded', function () {
    if (get_option('hys_data_version') !== HYS_DATA_VERSION && is_admin()) {
        HYS_Data::install();
    }
    if (class_exists('WooCommerce')) {
        HYS_Product::init();
    }
    HYS_Admin::init();
}, 20);

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
