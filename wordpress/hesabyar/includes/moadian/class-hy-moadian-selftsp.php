<?php
/**
 * "No certificate" self-TSP protocol (راهنمای اتصال بدون گواهی).
 *
 * Used by taxpayers who generated a key pair and registered only the public
 * key in کارپوشه. Requests are packets signed with RSA-SHA256 over the
 * SimpleNormalizer text; invoice data is XOR-ed with, then AES-256-GCM
 * encrypted by, a random key that is itself wrapped with RSA-OAEP-256.
 *
 * @package Hesabyar
 */

if (!defined('ABSPATH')) {
    exit;
}

class HY_Moadian_SelfTsp extends HY_Moadian_Transport {

    const PROD_URL = 'https://tp.tax.gov.ir/req/api/self-tsp/';
    const SANDBOX_URL = 'https://sandboxrc.tax.gov.ir/req/api/self-tsp/';

    private function url($path) {
        return rtrim($this->config['base_url'], '/') . '/' . ltrim($path, '/');
    }

    private function packet($type, $data = null, $with_fiscal_id = true, $uid = '') {
        return array(
            'uid' => $uid !== '' ? $uid : HY_Moadian_Util::uuid4(),
            'packetType' => $type,
            'retry' => false,
            'data' => $data,
            'encryptionKeyId' => '',
            'symmetricKey' => '',
            'iv' => '',
            'fiscalId' => $with_fiscal_id ? $this->config['fiscal_id'] : '',
            'dataSignature' => '',
        );
    }

    private function headers($token = null) {
        $h = array(
            'timestamp' => HY_Moadian_Util::now_ms(),
            'requestTraceId' => HY_Moadian_Util::uuid4(),
        );
        if ($token !== null) {
            $h['Authorization'] = 'Bearer ' . $token;
        }
        return $h;
    }

    /** Headers take part in the signature, with the "Bearer " prefix removed. */
    private static function signing_headers(array $headers) {
        if (isset($headers['Authorization'])) {
            $headers['Authorization'] = preg_replace('/^Bearer\s+/i', '', $headers['Authorization']);
        }
        return $headers;
    }

    private function sync($type, array $packet, $token = null, $sign = true) {
        $headers = $this->headers($token);
        $content = array(
            'packet' => $packet,
            'signature' => $sign
                ? HY_Moadian_Util::sign(
                    HY_Moadian_Util::normalize(array_merge($packet, self::signing_headers($headers))),
                    $this->config['private_key']
                )
                : null,
        );
        $headers['Content-Type'] = 'application/json';
        return $this->http('POST', $this->url('sync/' . rawurlencode($type)), $headers, HY_Moadian_Util::json_encode($content));
    }

    public function get_server_key() {
        $body = $this->sync('GET_SERVER_INFORMATION', $this->packet('GET_SERVER_INFORMATION', null, false), null, false);
        $keys = $body['result']['data']['publicKeys'] ?? array();
        if (empty($keys[0]['key']) || empty($keys[0]['id'])) {
            throw new HY_Moadian_Exception('کلید عمومی سازمان از سامانه دریافت نشد.', 0, $body);
        }
        return array('key' => (string) $keys[0]['key'], 'id' => (string) $keys[0]['id']);
    }

    public function get_token() {
        $packet = $this->packet('GET_TOKEN', array('username' => $this->config['fiscal_id']));
        $body = $this->sync('GET_TOKEN', $packet);
        $data = $body['result']['data'] ?? array();
        if (empty($data['token'])) {
            throw new HY_Moadian_Exception('توکن از سامانه دریافت نشد. شناسه حافظه و کلید را بررسی کنید.', 0, $body);
        }
        return array(
            'token' => (string) $data['token'],
            'expires_at' => self::expiry_to_unix($data['expiresIn'] ?? null),
        );
    }

    /** expiresIn has been seen both as an epoch (ms) and as a duration; handle both. */
    public static function expiry_to_unix($value) {
        $value = (float) $value;
        if ($value > 1e12) {
            return (int) floor($value / 1000);
        }
        if ($value > 1e9) {
            return (int) $value;
        }
        if ($value > 0) {
            return time() + (int) $value;
        }
        return time() + 1800;
    }

    public function send_invoices(array $invoices, $token, array $server_key) {
        if (!$invoices) {
            return array();
        }
        $aes_key = random_bytes(32);
        $iv = random_bytes(16);
        $wrapped_key = base64_encode(HY_Moadian_Util::rsa_oaep_sha256_encrypt(bin2hex($aes_key), $server_key['key']));

        $packets = array();
        foreach ($invoices as $item) {
            $invoice = HY_Moadian_Util::clean_numbers($item['invoice']);
            $packet = $this->packet('INVOICE.V01', $invoice, true, (string) $item['uid']);
            $packet['dataSignature'] = HY_Moadian_Util::sign(HY_Moadian_Util::normalize($invoice), $this->config['private_key']);

            $tag = '';
            $cipher = openssl_encrypt(
                HY_Moadian_Util::xor_bytes(HY_Moadian_Util::json_encode($invoice), $aes_key),
                'aes-256-gcm',
                $aes_key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16
            );
            if ($cipher === false) {
                throw new HY_Moadian_Exception('رمزنگاری صورتحساب ناموفق بود.');
            }
            $packet['data'] = base64_encode($cipher . $tag);
            $packet['iv'] = bin2hex($iv);
            $packet['symmetricKey'] = $wrapped_key;
            $packet['encryptionKeyId'] = (string) $server_key['id'];
            $packets[] = $packet;
        }

        $headers = $this->headers($token);
        // The SDK normalises the batch with one extra list level.
        $normalized = HY_Moadian_Util::normalize(array_merge(array('packets' => array($packets)), self::signing_headers($headers)));
        $content = array(
            'packets' => $packets,
            'signature' => HY_Moadian_Util::sign($normalized, $this->config['private_key']),
            'signatureKeyId' => null,
        );
        $headers['Content-Type'] = 'application/json';
        $body = $this->http('POST', $this->url('async/normal-enqueue'), $headers, HY_Moadian_Util::json_encode($content));

        $out = array();
        foreach (($body['result'] ?? array()) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $error = null;
            if (!empty($row['errorCode'])) {
                $detail = $row['errorDetail'] ?? '';
                $error = trim($row['errorCode'] . ' ' . (is_string($detail) ? $detail : HY_Moadian_Util::json_encode($detail)));
            }
            $out[(string) ($row['uid'] ?? '')] = array(
                'referenceNumber' => isset($row['referenceNumber']) ? (string) $row['referenceNumber'] : null,
                'error' => $error,
            );
        }
        return $out;
    }

    public function inquire_by_reference(array $reference_numbers, $token) {
        $packet = $this->packet('INQUIRY_BY_REFERENCE_NUMBER', array('referenceNumber' => array_values(array_map('strval', $reference_numbers))));
        $body = $this->sync('INQUIRY_BY_REFERENCE_NUMBER', $packet, $token);
        $items = $body['result']['data'] ?? array();
        $out = array();
        foreach ((array) $items as $item) {
            if (is_array($item)) {
                $out[] = self::normalize_inquiry_item($item);
            }
        }
        return $out;
    }

    public function get_fiscal_information($token) {
        $body = $this->sync('GET_FISCAL_INFORMATION', $this->packet('GET_FISCAL_INFORMATION', $this->config['fiscal_id']), $token);
        return $body['result']['data'] ?? $body;
    }

    public function get_economic_code_information($economic_code, $token) {
        $packet = $this->packet('GET_ECONOMIC_CODE_INFORMATION', array('economicCode' => (string) $economic_code));
        $body = $this->sync('GET_ECONOMIC_CODE_INFORMATION', $packet, $token);
        return $body['result']['data'] ?? $body;
    }
}
