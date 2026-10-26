<?php
/**
 * Plugin settings, encrypted secrets and key/CSR generation.
 *
 * @package Hesabyar
 */

if (!defined('ABSPATH')) {
    exit;
}

class HY_Settings {

    const OPTION = 'hy_settings';

    public static function defaults() {
        return array(
            // Moadian
            'protocol' => 'selftsp',          // selftsp | v2
            'environment' => 'production',    // production | sandbox
            'fiscal_id' => '',
            'economic_code' => '',
            'selftsp_url_production' => HY_Moadian_SelfTsp::PROD_URL,
            'selftsp_url_sandbox' => HY_Moadian_SelfTsp::SANDBOX_URL,
            'v2_url_production' => HY_Moadian_V2::PROD_URL,
            'v2_url_sandbox' => HY_Moadian_V2::SANDBOX_URL,
            'next_serial' => 1000,
            'auto_inquiry' => 1,
            // Business
            'seller_name' => get_bloginfo('name'),
            'seller_national_id' => '',
            'seller_postal_code' => '',
            'seller_address' => '',
            'seller_phone' => '',
            // Invoice defaults
            'default_vat' => 10,
            'default_unit_title' => 'عدد',
            'default_unit_code' => '1627',
            'default_stuff_id' => '',
            'default_inty' => 0,               // 0 = automatic (type 1 if buyer has an ID, else type 2)
            'default_setm' => 1,
            'next_invoice_number' => 1,
            // WooCommerce
            'wc_auto_create' => 0,
            'wc_auto_status' => 'completed',
            'wc_auto_send' => 0,
            'wc_amount_multiplier' => 0,       // 0 = detect from store currency (IRT → 10)
            'wc_national_id_meta' => '_billing_national_id',
            'wc_economic_code_meta' => '_billing_economic_code',
            'wc_shipping_stuff_id' => '',
        );
    }

    public static function all() {
        $saved = get_option(self::OPTION, array());
        return array_merge(self::defaults(), is_array($saved) ? $saved : array());
    }

    public static function get($key) {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function update(array $values) {
        $all = array_merge(self::all(), $values);
        update_option(self::OPTION, $all, false);
    }

    /** Atomically take the next value of a counter stored in the settings. */
    public static function take_counter($key) {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        $all = self::all();
        $value = max(1, (int) $all[$key]);
        $all[$key] = $value + 1;
        update_option(self::OPTION, $all, false);
        $wpdb->query('COMMIT');
        return $value;
    }

    /* ---------------- Secrets (private key / certificate) ---------------- */

    private static function secret_key() {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '') . 'hesabyar';
        return hash('sha256', $material, true);
    }

    public static function set_secret($name, $plain) {
        if ($plain === '' || $plain === null) {
            delete_option('hy_secret_' . $name);
            return;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::secret_key(), OPENSSL_RAW_DATA, $iv, $tag, 'hy_' . $name, 16);
        update_option('hy_secret_' . $name, base64_encode($iv . $tag . $cipher), false);
    }

    public static function get_secret($name) {
        $stored = get_option('hy_secret_' . $name, '');
        if ($stored === '') {
            return '';
        }
        $raw = base64_decode($stored);
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'hy_' . $name);
        return $plain === false ? '' : $plain;
    }

    public static function has_secret($name) {
        return get_option('hy_secret_' . $name, '') !== '';
    }

    /* ---------------- Keys ---------------- */

    /**
     * Generates a 2048-bit RSA key pair and a CSR. The private key is stored
     * encrypted; the public key and CSR are returned for the user to register.
     */
    public static function generate_keys(array $subject) {
        $config = array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256');
        $key = openssl_pkey_new($config);
        if (!$key) {
            throw new RuntimeException('ساخت کلید ناموفق بود: ' . openssl_error_string());
        }
        $private = '';
        openssl_pkey_export($key, $private);
        $details = openssl_pkey_get_details($key);
        $public = $details['key'];

        $dn = array_filter(array(
            'countryName' => 'IR',
            'stateOrProvinceName' => $subject['state'] ?? '',
            'localityName' => $subject['city'] ?? '',
            'organizationName' => $subject['organization'] ?? '',
            'organizationalUnitName' => $subject['unit'] ?? '',
            'commonName' => $subject['common_name'] ?? '',
            'serialNumber' => $subject['serial_number'] ?? '',
        ), 'strlen');
        $csr_pem = '';
        $csr = @openssl_csr_new($dn, $key, $config);
        if ($csr) {
            openssl_csr_export($csr, $csr_pem);
        }

        self::set_secret('private_key', $private);
        update_option('hy_public_key', $public, false);
        update_option('hy_csr', $csr_pem, false);
        return array('public_key' => $public, 'csr' => $csr_pem);
    }

    /** Saves a private key the user pasted, deriving and storing its public key. */
    public static function import_private_key($pem) {
        $pem = trim(str_replace("\r", '', (string) $pem));
        if ($pem !== '' && strpos($pem, '-----BEGIN') === false) {
            $pem = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $pem), 64, "\n") . "-----END PRIVATE KEY-----";
        }
        $key = openssl_pkey_get_private($pem);
        if (!$key) {
            throw new RuntimeException('کلید خصوصی واردشده معتبر نیست.');
        }
        $details = openssl_pkey_get_details($key);
        self::set_secret('private_key', $pem);
        update_option('hy_public_key', $details['key'], false);
    }

    public static function public_key() {
        return (string) get_option('hy_public_key', '');
    }

    public static function public_key_base64() {
        return preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', self::public_key());
    }

    /* ---------------- Transport factory ---------------- */

    public static function transport($http = null) {
        $s = self::all();
        $protocol = $s['protocol'] === 'v2' ? 'v2' : 'selftsp';
        $env = $s['environment'] === 'sandbox' ? 'sandbox' : 'production';
        $config = array(
            'base_url' => $s[$protocol . '_url_' . $env],
            'fiscal_id' => $s['fiscal_id'],
            'economic_code' => $s['economic_code'],
            'private_key' => self::get_secret('private_key'),
            'certificate' => self::get_secret('certificate'),
        );
        return $protocol === 'v2' ? new HY_Moadian_V2($config, $http) : new HY_Moadian_SelfTsp($config, $http);
    }
}
