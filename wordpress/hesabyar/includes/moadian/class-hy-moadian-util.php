<?php
/**
 * Shared cryptography and encoding helpers for the Moadian protocol.
 *
 * Dependency-free: everything here uses PHP's OpenSSL extension only, so the
 * plugin can be installed on shared hosting without Composer.
 *
 * @package Hesabyar
 */

if (!defined('ABSPATH')) {
    exit;
}

class HY_Moadian_Exception extends RuntimeException {
    /** @var int */
    private $http_status;

    /** @var mixed */
    private $response_data;

    public function __construct($message, $http_status = 0, $response_data = null) {
        parent::__construct((string) $message);
        $this->http_status = (int) $http_status;
        $this->response_data = $response_data;
    }

    public function get_http_status() {
        return $this->http_status;
    }

    public function get_response_data() {
        return $this->response_data;
    }
}

class HY_Moadian_Util {

    /**
     * "SimpleNormalizer" from the INTA SDK: flatten nested arrays to dotted keys,
     * sort keys, then join values with "#". Null/empty become "#", literal "#"
     * is escaped as "##", booleans become true/false.
     */
    public static function normalize(array $data) {
        $flat = self::flatten($data);
        ksort($flat, SORT_STRING);
        $values = array();
        foreach ($flat as $value) {
            if (is_bool($value)) {
                $values[] = $value ? 'true' : 'false';
            } elseif ($value === null || $value === '') {
                $values[] = '#';
            } else {
                $values[] = str_replace('#', '##', (string) $value);
            }
        }
        return implode('#', $values);
    }

    private static function flatten(array $data, $prefix = '') {
        $out = array();
        foreach ($data as $key => $value) {
            $flat_key = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $out = array_merge($out, self::flatten($value, $flat_key));
            } else {
                $out[$flat_key] = $value;
            }
        }
        return $out;
    }

    /**
     * Whole-number floats are converted to ints so that the JSON the server
     * receives ("10") and the text we sign ("10") are identical.
     */
    public static function clean_numbers($value) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::clean_numbers($v);
            }
            return $value;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }
        return $value;
    }

    public static function json_encode($value) {
        $json = json_encode($value);
        if ($json === false) {
            throw new HY_Moadian_Exception('JSON encoding failed: ' . json_last_error_msg());
        }
        return $json;
    }

    /** RSA-SHA256 signature, base64 encoded. */
    public static function sign($text, $private_key_pem) {
        $key = openssl_pkey_get_private($private_key_pem);
        if (!$key) {
            throw new HY_Moadian_Exception('کلید خصوصی نامعتبر است.');
        }
        $signature = '';
        if (!openssl_sign((string) $text, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new HY_Moadian_Exception('امضای درخواست ناموفق بود: ' . openssl_error_string());
        }
        return base64_encode($signature);
    }

    /**
     * RSA-OAEP with SHA-256 for both the hash and MGF1 (what the tax
     * organisation calls RSA-OAEP-256). PHP's openssl_public_encrypt only
     * offers OAEP-SHA1, so the padding is built here and the raw RSA
     * operation is done with OPENSSL_NO_PADDING.
     */
    public static function rsa_oaep_sha256_encrypt($message, $public_key) {
        $key = openssl_pkey_get_public(self::format_public_key($public_key));
        if (!$key) {
            throw new HY_Moadian_Exception('کلید عمومی سازمان مالیاتی نامعتبر است.');
        }
        $details = openssl_pkey_get_details($key);
        $k = (int) ceil(((int) $details['bits']) / 8);
        $h_len = 32;
        $m_len = strlen($message);
        if ($m_len > $k - 2 * $h_len - 2) {
            throw new HY_Moadian_Exception('پیام برای رمزنگاری OAEP بیش از حد بلند است.');
        }
        $l_hash = hash('sha256', '', true);
        $ps = str_repeat("\0", $k - $m_len - 2 * $h_len - 2);
        $db = $l_hash . $ps . "\x01" . $message;
        $seed = random_bytes($h_len);
        $masked_db = self::xor_bytes($db, self::mgf1($seed, $k - $h_len - 1));
        $masked_seed = self::xor_bytes($seed, self::mgf1($masked_db, $h_len));
        $em = "\0" . $masked_seed . $masked_db;
        $out = '';
        if (!openssl_public_encrypt($em, $out, $key, OPENSSL_NO_PADDING)) {
            throw new HY_Moadian_Exception('رمزنگاری کلید متقارن ناموفق بود.');
        }
        return $out;
    }

    /** Inverse of rsa_oaep_sha256_encrypt; used only by the test-suite. */
    public static function rsa_oaep_sha256_decrypt($cipher, $private_key_pem) {
        $key = openssl_pkey_get_private($private_key_pem);
        $raw = '';
        openssl_private_decrypt($cipher, $raw, $key, OPENSSL_NO_PADDING);
        $h_len = 32;
        $masked_seed = substr($raw, 1, $h_len);
        $masked_db = substr($raw, 1 + $h_len);
        $seed = self::xor_bytes($masked_seed, self::mgf1($masked_db, $h_len));
        $db = self::xor_bytes($masked_db, self::mgf1($seed, strlen($masked_db)));
        $rest = substr($db, $h_len);
        $pos = strpos($rest, "\x01");
        return substr($rest, $pos + 1);
    }

    private static function mgf1($seed, $length) {
        $mask = '';
        for ($counter = 0; strlen($mask) < $length; $counter++) {
            $mask .= hash('sha256', $seed . pack('N', $counter), true);
        }
        return substr($mask, 0, $length);
    }

    public static function xor_bytes($source, $key) {
        $out = '';
        $key_len = strlen($key);
        $len = strlen($source);
        for ($i = 0; $i < $len; $i++) {
            $out .= $source[$i] ^ $key[$i % $key_len];
        }
        return $out;
    }

    public static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64url_decode($data) {
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /** Accepts a PEM or a bare base64 DER key and returns PEM. */
    public static function format_public_key($key) {
        $key = trim((string) $key);
        if (strpos($key, '-----BEGIN') !== false) {
            return $key;
        }
        $key = preg_replace('/\s+/', '', $key);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($key, 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Certificate (PEM or base64 DER) → base64 DER for the JWS x5c header. */
    public static function certificate_to_x5c($certificate) {
        $certificate = trim((string) $certificate);
        $certificate = preg_replace('/-----(BEGIN|END) CERTIFICATE-----/', '', $certificate);
        return preg_replace('/\s+/', '', $certificate);
    }

    public static function uuid4() {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    public static function now_ms() {
        return (string) (int) floor(microtime(true) * 1000);
    }

    /**
     * 22-character unique tax ID ("شماره منحصر به فرد مالیاتی"):
     * memory ID (6) + hex days since epoch (5) + hex serial (10) + Verhoeff digit.
     *
     * @param string $fiscal_id  six-character memory ID
     * @param int    $serial     internal serial, at most 12 decimal digits
     * @param int    $timestamp  invoice time, unix seconds
     */
    public static function generate_tax_id($fiscal_id, $serial, $timestamp) {
        $fiscal_id = strtoupper(trim((string) $fiscal_id));
        if (!preg_match('/^[A-Z0-9]{6}$/', $fiscal_id)) {
            throw new HY_Moadian_Exception('شناسه یکتای حافظه مالیاتی باید ۶ کاراکتر (حرف انگلیسی یا عدد) باشد.');
        }
        $serial = (int) $serial;
        if ($serial < 1 || strlen((string) $serial) > 12) {
            throw new HY_Moadian_Exception('سریال صورتحساب باید عددی مثبت و حداکثر ۱۲ رقمی باشد.');
        }
        $days = (int) floor(((int) $timestamp) / 86400);
        $numeric_id = '';
        foreach (str_split($fiscal_id) as $ch) {
            $numeric_id .= ctype_digit($ch) ? $ch : (string) ord($ch);
        }
        $control = $numeric_id . str_pad((string) $days, 6, '0', STR_PAD_LEFT) . str_pad((string) $serial, 12, '0', STR_PAD_LEFT);
        return strtoupper(
            $fiscal_id
            . str_pad(dechex($days), 5, '0', STR_PAD_LEFT)
            . str_pad(dechex($serial), 10, '0', STR_PAD_LEFT)
            . self::verhoeff($control)
        );
    }

    /** Internal invoice number (inno): serial as 10-digit uppercase hex. */
    public static function invoice_number($serial) {
        return strtoupper(str_pad(dechex((int) $serial), 10, '0', STR_PAD_LEFT));
    }

    public static function verhoeff($number) {
        static $d = array(
            array(0,1,2,3,4,5,6,7,8,9), array(1,2,3,4,0,6,7,8,9,5), array(2,3,4,0,1,7,8,9,5,6),
            array(3,4,0,1,2,8,9,5,6,7), array(4,0,1,2,3,9,5,6,7,8), array(5,9,8,7,6,0,4,3,2,1),
            array(6,5,9,8,7,1,0,4,3,2), array(7,6,5,9,8,2,1,0,4,3), array(8,7,6,5,9,3,2,1,0,4),
            array(9,8,7,6,5,4,3,2,1,0),
        );
        static $p = array(
            array(0,1,2,3,4,5,6,7,8,9), array(1,5,7,6,2,8,3,0,9,4), array(5,8,0,3,7,9,6,1,4,2),
            array(8,9,1,6,0,4,3,5,2,7), array(9,4,5,3,1,2,6,8,7,0), array(4,2,8,6,5,7,3,9,0,1),
            array(2,7,9,3,8,0,6,4,1,5), array(7,0,4,6,9,1,3,2,5,8),
        );
        static $inv = array(0,4,3,2,1,5,6,7,8,9);
        $c = 0;
        $digits = array_reverse(str_split((string) $number));
        foreach ($digits as $i => $digit) {
            $c = $d[$c][$p[($i + 1) % 8][(int) $digit]];
        }
        return $inv[$c];
    }

    /** Pulls a human-readable error out of any Moadian error body. */
    public static function error_message($body, $fallback = 'سامانه مودیان درخواست را رد کرد.') {
        if (!is_array($body)) {
            return $fallback;
        }
        if (!empty($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }
        $lists = array();
        if (!empty($body['errors']) && is_array($body['errors'])) {
            $lists = $body['errors'];
        } elseif (!empty($body['result']['errors']) && is_array($body['result']['errors'])) {
            $lists = $body['result']['errors'];
        }
        $messages = array();
        foreach ($lists as $err) {
            if (is_array($err)) {
                $messages[] = trim(($err['code'] ?? $err['errorCode'] ?? '') . ' ' . ($err['message'] ?? $err['detail'] ?? ''));
            } elseif (is_string($err)) {
                $messages[] = $err;
            }
        }
        return $messages ? implode(' | ', $messages) : $fallback;
    }
}
