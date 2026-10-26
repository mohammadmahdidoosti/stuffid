<?php
/**
 * Certificate based protocol (requestsmanager/api/v2).
 *
 * Every authenticated call carries a JWS (RS256, x5c = signing certificate)
 * over a fresh nonce. Invoices are signed as JWS and then encrypted as JWE
 * (RSA-OAEP-256 + A256GCM) with the tax organisation's public key.
 *
 * @package Hesabyar
 */

if (!defined('ABSPATH')) {
    exit;
}

class HY_Moadian_V2 extends HY_Moadian_Transport {

    const PROD_URL = 'https://tp.tax.gov.ir/requestsmanager/api/v2/';
    const SANDBOX_URL = 'https://sandboxrc.tax.gov.ir/requestsmanager/api/v2/';

    public function __construct(array $config, $http = null) {
        parent::__construct($config, $http);
        if (trim((string) $this->config['certificate']) === '') {
            throw new HY_Moadian_Exception('برای روش «با گواهی» باید فایل گواهی امضا را در تنظیمات وارد کنید.');
        }
    }

    private function url($path, array $query = array()) {
        $url = rtrim($this->config['base_url'], '/') . '/' . ltrim($path, '/');
        return $query ? $url . '?' . http_build_query($query) : $url;
    }

    /** RS256 compact JWS with the headers the tax organisation requires. */
    public function jws(array $payload) {
        $header = array(
            'typ' => 'JWT',
            'alg' => 'RS256',
            'x5c' => array(HY_Moadian_Util::certificate_to_x5c($this->config['certificate'])),
            'sigT' => gmdate('Y-m-d\TH:i:s\Z'),
            'crit' => array('sigT'),
            'cty' => 'text/plain',
        );
        $input = HY_Moadian_Util::base64url_encode(HY_Moadian_Util::json_encode($header))
            . '.' . HY_Moadian_Util::base64url_encode(HY_Moadian_Util::json_encode($payload));
        $key = openssl_pkey_get_private($this->config['private_key']);
        $sig = '';
        if (!openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256)) {
            throw new HY_Moadian_Exception('امضای JWS ناموفق بود.');
        }
        return $input . '.' . HY_Moadian_Util::base64url_encode($sig);
    }

    /** Compact JWE: RSA-OAEP-256 key wrap + AES-256-GCM content encryption. */
    public static function jwe($plaintext, array $server_key) {
        $header = array('alg' => 'RSA-OAEP-256', 'enc' => 'A256GCM', 'kid' => (string) $server_key['id']);
        $cek = random_bytes(32);
        $iv = random_bytes(12);
        $protected = HY_Moadian_Util::base64url_encode(HY_Moadian_Util::json_encode($header));
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $cek, OPENSSL_RAW_DATA, $iv, $tag, $protected, 16);
        if ($cipher === false) {
            throw new HY_Moadian_Exception('رمزنگاری JWE ناموفق بود.');
        }
        return implode('.', array(
            $protected,
            HY_Moadian_Util::base64url_encode(HY_Moadian_Util::rsa_oaep_sha256_encrypt($cek, $server_key['key'])),
            HY_Moadian_Util::base64url_encode($iv),
            HY_Moadian_Util::base64url_encode($cipher),
            HY_Moadian_Util::base64url_encode($tag),
        ));
    }

    private function nonce() {
        $body = $this->http('GET', $this->url('nonce', array('timeToLive' => 30)), array('accept' => '*/*'));
        if (empty($body['nonce'])) {
            throw new HY_Moadian_Exception('nonce از سامانه دریافت نشد.', 0, $body);
        }
        return (string) $body['nonce'];
    }

    public function get_token() {
        $token = $this->jws(array('nonce' => $this->nonce(), 'clientId' => $this->config['fiscal_id']));
        // Each token is bound to a short-lived nonce, so it is only cached briefly.
        return array('token' => $token, 'expires_at' => time() + 20);
    }

    private function auth_headers($token) {
        return array('accept' => '*/*', 'Authorization' => 'Bearer ' . $token);
    }

    public function get_server_key() {
        $token = $this->get_token();
        $body = $this->http('GET', $this->url('server-information'), $this->auth_headers($token['token']));
        $keys = $body['publicKeys'] ?? ($body['result']['publicKeys'] ?? array());
        if (empty($keys[0]['key']) || empty($keys[0]['id'])) {
            throw new HY_Moadian_Exception('کلید عمومی سازمان از سامانه دریافت نشد.', 0, $body);
        }
        return array('key' => (string) $keys[0]['key'], 'id' => (string) $keys[0]['id']);
    }

    public function send_invoices(array $invoices, $token, array $server_key) {
        $packets = array();
        $uids = array();
        foreach ($invoices as $item) {
            $invoice = HY_Moadian_Util::clean_numbers($item['invoice']);
            $packets[] = array(
                'payload' => self::jwe($this->jws($invoice), $server_key),
                'header' => array(
                    'requestTraceId' => (string) $item['uid'],
                    'fiscalId' => $this->config['fiscal_id'],
                ),
            );
            $uids[] = (string) $item['uid'];
        }
        $headers = $this->auth_headers($token);
        $headers['Content-Type'] = 'application/json';
        $body = $this->http('POST', $this->url('invoice'), $headers, HY_Moadian_Util::json_encode($packets));

        $rows = isset($body['result']) ? $body['result'] : (is_array($body) ? $body : array());
        $out = array();
        foreach (array_values((array) $rows) as $i => $row) {
            if (!is_array($row) || !isset($uids[$i])) {
                continue;
            }
            $error = null;
            if (!empty($row['errorCode']) || !empty($row['error'])) {
                $error = trim(($row['errorCode'] ?? '') . ' ' . HY_Moadian_Util::json_encode($row['errorDetail'] ?? ($row['error'] ?? '')));
            }
            $out[$uids[$i]] = array(
                'referenceNumber' => isset($row['referenceNumber']) ? (string) $row['referenceNumber'] : null,
                'error' => $error,
                'serverUid' => $row['uid'] ?? null,
            );
        }
        return $out;
    }

    public function inquire_by_reference(array $reference_numbers, $token) {
        $body = $this->http(
            'GET',
            $this->url('inquiry-by-reference-id', array('referenceIds' => implode(',', $reference_numbers))),
            $this->auth_headers($token)
        );
        $items = isset($body['result']) ? $body['result'] : $body;
        if (isset($items['data']) && is_array($items['data'])) {
            $items = $items['data'];
        }
        $out = array();
        foreach ((array) $items as $item) {
            if (is_array($item)) {
                $out[] = self::normalize_inquiry_item($item);
            }
        }
        return $out;
    }

    public function get_fiscal_information($token) {
        return $this->http('GET', $this->url('fiscal-information', array('memoryId' => $this->config['fiscal_id'])), $this->auth_headers($token));
    }

    public function get_economic_code_information($economic_code, $token) {
        return $this->http('GET', $this->url('taxpayer', array('economicCode' => (string) $economic_code)), $this->auth_headers($token));
    }
}
