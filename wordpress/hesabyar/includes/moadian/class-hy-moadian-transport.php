<?php
/**
 * Base class for the two ways of talking to Moadian:
 *  - HY_Moadian_SelfTsp : the "no certificate" protocol (key registered in کارپوشه)
 *  - HY_Moadian_V2      : the certificate based JWS/JWE protocol
 *
 * Both return the same normalised shapes so the rest of the plugin does not
 * care which one is in use.
 *
 * @package Hesabyar
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class HY_Moadian_Transport {

    /** @var array */
    protected $config;

    /** @var callable|null  function($method, $url, array $headers, ?string $body): array [status, body] */
    protected $http;

    /** @var array  last raw exchange, kept for the log */
    public $last_exchange = array();

    public function __construct(array $config, $http = null) {
        $this->config = array_merge(array(
            'base_url' => '',
            'fiscal_id' => '',
            'economic_code' => '',
            'private_key' => '',
            'certificate' => '',
            'timeout' => 30,
        ), $config);
        $this->config['fiscal_id'] = strtoupper(trim($this->config['fiscal_id']));
        $this->http = $http;

        if (!preg_match('/^[A-Z0-9]{6}$/', $this->config['fiscal_id'])) {
            throw new HY_Moadian_Exception('شناسه یکتای حافظه مالیاتی در تنظیمات وارد نشده یا نادرست است.');
        }
        if (!openssl_pkey_get_private($this->config['private_key'])) {
            throw new HY_Moadian_Exception('کلید خصوصی در تنظیمات وارد نشده یا نادرست است.');
        }
    }

    /** @return array ['key' => base64/PEM public key, 'id' => key id] */
    abstract public function get_server_key();

    /** @return array ['token' => string, 'expires_at' => unix seconds] */
    abstract public function get_token();

    /**
     * @param array  $invoices  list of ['uid' => string, 'invoice' => array (INVOICE.V01)]
     * @param string $token
     * @param array  $server_key ['key','id']
     * @return array uid => ['referenceNumber' => ?string, 'error' => ?string]
     */
    abstract public function send_invoices(array $invoices, $token, array $server_key);

    /**
     * @return array list of ['referenceNumber','uid','status' (SUCCESS|FAILED|PENDING|NOT_FOUND),'errors' => string[],'warnings' => string[]]
     */
    abstract public function inquire_by_reference(array $reference_numbers, $token);

    abstract public function get_fiscal_information($token);

    abstract public function get_economic_code_information($economic_code, $token);

    protected function http($method, $url, array $headers, $body = null) {
        $this->last_exchange = array(
            'method' => $method,
            'url' => $url,
            'request' => $body,
        );

        if ($this->http) {
            list($status, $raw) = call_user_func($this->http, $method, $url, $headers, $body);
        } else {
            $args = array(
                'method' => strtoupper($method),
                'headers' => $headers,
                'timeout' => (int) $this->config['timeout'],
            );
            if ($body !== null) {
                $args['body'] = $body;
                $args['data_format'] = 'body';
            }
            $response = wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                $this->last_exchange['response'] = $response->get_error_message();
                throw new HY_Moadian_Exception('ارتباط با سامانه مودیان برقرار نشد: ' . $response->get_error_message());
            }
            $status = (int) wp_remote_retrieve_response_code($response);
            $raw = wp_remote_retrieve_body($response);
        }

        $this->last_exchange['status'] = $status;
        $this->last_exchange['response'] = $raw;

        $decoded = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300) {
            $message = HY_Moadian_Util::error_message($decoded, 'خطای HTTP ' . $status . ' از سامانه مودیان');
            throw new HY_Moadian_Exception($message, $status, $decoded);
        }
        if ($decoded === null && trim((string) $raw) !== '' && trim((string) $raw) !== 'null') {
            throw new HY_Moadian_Exception('پاسخ سامانه مودیان قابل خواندن نبود.', $status, $raw);
        }
        return $decoded;
    }

    /**
     * Turns one inquiry record from either protocol into the common shape.
     */
    protected static function normalize_inquiry_item(array $item) {
        $status = strtoupper((string) ($item['status'] ?? 'PENDING'));
        $data = isset($item['data']) && is_array($item['data']) ? $item['data'] : array();
        $errors = array();
        $warnings = array();
        foreach (array('error', 'errors') as $k) {
            if (!empty($data[$k]) && is_array($data[$k])) {
                foreach ($data[$k] as $e) {
                    $errors[] = is_array($e) ? trim(($e['code'] ?? '') . ' ' . ($e['message'] ?? '')) : (string) $e;
                }
            }
        }
        foreach (array('warning', 'warnings') as $k) {
            if (!empty($data[$k]) && is_array($data[$k])) {
                foreach ($data[$k] as $w) {
                    $warnings[] = is_array($w) ? trim(($w['code'] ?? '') . ' ' . ($w['message'] ?? '')) : (string) $w;
                }
            }
        }
        if ($status !== 'FAILED' && !empty($data['success']) && empty($errors)) {
            $status = 'SUCCESS';
        }
        return array(
            'referenceNumber' => (string) ($item['referenceNumber'] ?? ''),
            'uid' => (string) ($item['uid'] ?? ''),
            'status' => $status,
            'errors' => array_values(array_filter($errors)),
            'warnings' => array_values(array_filter($warnings)),
        );
    }
}
