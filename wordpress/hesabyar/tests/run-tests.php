<?php
/**
 * Dependency-free protocol tests. Run:  php tests/run-tests.php
 *
 * A fake Moadian server verifies every signature with the taxpayer's public
 * key and decrypts the invoice with its own private key, so the whole
 * sign → encrypt → send path is checked end-to-end without network access.
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
define('ABSPATH', __DIR__ . '/');
$base = dirname(__DIR__) . '/includes/';
require $base . 'moadian/class-hy-moadian-util.php';
require $base . 'moadian/class-hy-moadian-transport.php';
require $base . 'moadian/class-hy-moadian-selftsp.php';
require $base . 'moadian/class-hy-moadian-v2.php';
require $base . 'class-hy-jalali.php';

$fails = 0;
$passes = 0;
function check($cond, $msg) {
    global $fails, $passes;
    if ($cond) {
        $passes++;
        echo "  ✔ $msg\n";
    } else {
        $fails++;
        echo "  ✘ $msg\n";
    }
}
function same($expected, $actual, $msg) {
    check($expected === $actual, $msg . ($expected === $actual ? '' : "\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true)));
}

echo "Normalizer\n";
same('1#132#ABC', HY_Moadian_Util::normalize(array('K3' => 'ABC', 'K1' => 1, 'K2' => '132')), 'sorts keys');
same('700#FGB#ABC#12.94', HY_Moadian_Util::normalize(array('KD' => 12.94, 'KB' => 'ABC', 'KA' => array('B' => 'FGB', 'A' => '700'))), 'flattens nested');
same('1#2#3#4#ABC#12.94', HY_Moadian_Util::normalize(array('KD' => 12.94, 'KB' => 'ABC', 'KA' => array(array('B' => 2, 'A' => 1), array('A' => 3, 'B' => 4)))), 'flattens lists');
same('v1#v4#v5#v2', HY_Moadian_Util::normalize(array('k2' => 'v1', 'k4' => 'v2', 'k3' => array('k1' => 'v4', 'k5' => 'v5'))), 'documentation sample');
same('##a##b#true', HY_Moadian_Util::normalize(array('a' => null, 'b' => 'a#b', 'c' => true)), 'null, # escaping, boolean');

echo "Tax ID (SDK vectors)\n";
$utc = new DateTimeZone('UTC');
foreach (array(
    array('DEF5GH', '2020-07-20 01:00:10', 12, 'DEF5GH0481F000000000C2'),
    array('DEF5GH', '2020-07-20 08:30:30', 8173, 'DEF5GH0481F0000001FED8'),
    array('DEF5GH', '2020-07-20 23:11:12', 2572613409, 'DEF5GH0481F009956F7211'),
) as $v) {
    same($v[3], HY_Moadian_Util::generate_tax_id($v[0], $v[2], (new DateTime($v[1], $utc))->getTimestamp()), "taxid {$v[3]}");
}
same('00000003E8', HY_Moadian_Util::invoice_number(1000), 'inno is 10-char hex');

echo "Jalali\n";
same('1405/07/23', HY_Jalali::format((new DateTime('2026-10-15 12:00', $utc))->getTimestamp()), '2026-10-15 → 1405/07/23');
same('1403/12/30', HY_Jalali::format((new DateTime('2025-03-20 12:00', $utc))->getTimestamp()), 'leap year end 1403/12/30');
$ts = HY_Jalali::parse('۱۴۰۵/۰۷/۲۳', '16:08');
same('1405/07/23 16:08', HY_Jalali::format($ts, true), 'parse Persian digits round-trip');
same(null, HY_Jalali::parse('1405/13/01'), 'rejects month 13');

echo "Number cleaning\n";
same('{"a":10,"b":9.5,"c":[3]}', json_encode(HY_Moadian_Util::clean_numbers(array('a' => 10.0, 'b' => 9.5, 'c' => array(3.0)))), 'whole floats become ints');

echo "RSA-OAEP-256\n";
$tax_org = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
openssl_pkey_export($tax_org, $tax_org_private);
$tax_org_public = openssl_pkey_get_details($tax_org)['key'];
$tax_org_public_b64 = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $tax_org_public);
$secret = bin2hex(random_bytes(32));
$wrapped = HY_Moadian_Util::rsa_oaep_sha256_encrypt($secret, $tax_org_public_b64);
same($secret, HY_Moadian_Util::rsa_oaep_sha256_decrypt($wrapped, $tax_org_private), 'wrap/unwrap round trip (base64 key)');
// Cross-check with OpenSSL CLI, which implements OAEP-SHA256 independently.
$tmp = sys_get_temp_dir() . '/hy_oaep_' . getmypid();
file_put_contents("$tmp.key", $tax_org_private);
file_put_contents("$tmp.bin", $wrapped);
$cli = shell_exec("openssl pkeyutl -decrypt -inkey $tmp.key -in $tmp.bin -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:sha256 -pkeyopt rsa_mgf1_md:sha256 2>&1");
same($secret, $cli, 'OpenSSL CLI decrypts our OAEP-SHA256 output');
@unlink("$tmp.key");
@unlink("$tmp.bin");

/* -------------------- fake server -------------------- */
$taxpayer = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
openssl_pkey_export($taxpayer, $taxpayer_private);
$taxpayer_public = openssl_pkey_get_details($taxpayer)['key'];

$invoice = array(
    'header' => array(
        'taxid' => HY_Moadian_Util::generate_tax_id('A3P5RG', 1000, 1760531280),
        'indatim' => 1760531280000, 'indati2m' => 1760531280000, 'inty' => 1, 'inno' => '00000003E8',
        'irtaxid' => null, 'inp' => 1, 'ins' => 1, 'tins' => '00182842310001', 'tob' => 2,
        'bid' => '10861867895', 'tinb' => '10861867895', 'bpc' => '4143193146',
        'tprdis' => 40200000, 'tdis' => 0, 'tadis' => 40200000, 'tvam' => 4020000, 'todam' => 0, 'tbill' => 44220000,
        'setm' => 1, 'cap' => 44220000, 'insp' => 0, 'tvop' => null, 'tax17' => 0,
    ),
    'body' => array(array(
        'sstid' => '2330000000000', 'sstt' => 'شیر ربع گرد سه راهه هیدرولیک KHB 1/2"', 'am' => 1, 'mu' => '1627',
        'fee' => 40200000, 'prdis' => 40200000, 'dis' => 0, 'adis' => 40200000, 'vra' => 10.0, 'vam' => 4020000, 'tsstam' => 44220000,
    )),
    'payments' => array(),
);

$server_log = array();
$fake = function ($method, $url, $headers, $body) use (&$server_log, $taxpayer_public, $tax_org_private, $tax_org_public_b64) {
    $req = json_decode((string) $body, true);
    $sig_headers = array_diff_key($headers, array('Content-Type' => 1, 'accept' => 1));
    if (isset($sig_headers['Authorization'])) {
        $sig_headers['Authorization'] = preg_replace('/^Bearer\s+/', '', $sig_headers['Authorization']);
    }
    $path = parse_url($url, PHP_URL_PATH);
    $entry = array('path' => $path);
    if (preg_match('#/sync/(\w+(\.\w+)?)$#', $path, $m)) {
        $type = $m[1];
        if ($type !== 'GET_SERVER_INFORMATION') {
            $entry['sig_ok'] = openssl_verify(HY_Moadian_Util::normalize(array_merge($req['packet'], $sig_headers)), base64_decode($req['signature']), $taxpayer_public, OPENSSL_ALGO_SHA256) === 1;
        }
        $server_log[] = $entry;
        switch ($type) {
            case 'GET_SERVER_INFORMATION':
                return array(200, json_encode(array('result' => array('data' => array('publicKeys' => array(array('key' => $tax_org_public_b64, 'id' => 'key-1')))))));
            case 'GET_TOKEN':
                return array(200, json_encode(array('result' => array('data' => array('token' => 'tok-123', 'expiresIn' => (time() + 3600) * 1000)))));
            case 'INQUIRY_BY_REFERENCE_NUMBER':
                $out = array();
                foreach ($req['packet']['data']['referenceNumber'] as $ref) {
                    $out[] = array('referenceNumber' => $ref, 'uid' => 'u', 'status' => 'SUCCESS', 'data' => array('error' => array(), 'warning' => array(array('code' => 'W1', 'message' => 'ok'))));
                }
                return array(200, json_encode(array('result' => array('data' => $out))));
        }
    }
    if (substr($path, -20) === 'async/normal-enqueue') {
        $packets = $req['packets'];
        $entry['batch_sig_ok'] = openssl_verify(HY_Moadian_Util::normalize(array_merge(array('packets' => array($packets)), $sig_headers)), base64_decode($req['signature']), $taxpayer_public, OPENSSL_ALGO_SHA256) === 1;
        $result = array();
        foreach ($packets as $p) {
            $aes = hex2bin(HY_Moadian_Util::rsa_oaep_sha256_decrypt(base64_decode($p['symmetricKey']), $tax_org_private));
            $raw = base64_decode($p['data']);
            $plain = openssl_decrypt(substr($raw, 0, -16), 'aes-256-gcm', $aes, OPENSSL_RAW_DATA, hex2bin($p['iv']), substr($raw, -16), '');
            $data = json_decode(HY_Moadian_Util::xor_bytes($plain, $aes), true);
            $entry['decrypted'] = $data;
            $entry['data_sig_ok'] = openssl_verify(HY_Moadian_Util::normalize($data), base64_decode($p['dataSignature']), $taxpayer_public, OPENSSL_ALGO_SHA256) === 1;
            $entry['key_id'] = $p['encryptionKeyId'];
            $result[] = array('uid' => $p['uid'], 'referenceNumber' => 'ref-' . $p['uid'], 'errorCode' => null, 'errorDetail' => null);
        }
        $server_log[] = $entry;
        return array(200, json_encode(array('result' => $result)));
    }
    return array(404, '{"message":"not found"}');
};

echo "Self-TSP end to end (fake server)\n";
$client = new HY_Moadian_SelfTsp(array('base_url' => 'https://example.test/req/api/self-tsp/', 'fiscal_id' => 'a3p5rg', 'private_key' => $taxpayer_private), $fake);
$key = $client->get_server_key();
same('key-1', $key['id'], 'server key id read');
$token = $client->get_token();
same('tok-123', $token['token'], 'token read');
check($token['expires_at'] > time() + 3000, 'token expiry parsed from ms epoch');
check(end($server_log)['sig_ok'] === true, 'GET_TOKEN request signature verifies');
$res = $client->send_invoices(array(array('uid' => 'uid-1', 'invoice' => $invoice)), $token['token'], $key);
$last = end($server_log);
check($last['batch_sig_ok'], 'batch signature verifies');
check($last['data_sig_ok'], 'invoice dataSignature verifies after server-side decrypt');
same('key-1', $last['key_id'], 'encryptionKeyId sent');
same($invoice['header']['taxid'], $last['decrypted']['header']['taxid'], 'decrypted invoice matches');
same(10, $last['decrypted']['body'][0]['vra'], 'vra 10.0 sent as integer 10');
same('ref-uid-1', $res['uid-1']['referenceNumber'], 'referenceNumber mapped by uid');
$inq = $client->inquire_by_reference(array('ref-uid-1'), $token['token']);
same('SUCCESS', $inq[0]['status'], 'inquiry status');
same(array('W1 ok'), $inq[0]['warnings'], 'inquiry warnings');
check(end($server_log)['sig_ok'], 'inquiry signature verifies');

echo "V2 JWS/JWE\n";
$cert = openssl_csr_sign(openssl_csr_new(array('commonName' => 'test'), $taxpayer), null, $taxpayer, 365);
openssl_x509_export($cert, $cert_pem);
$v2 = new HY_Moadian_V2(array('base_url' => 'https://example.test/v2/', 'fiscal_id' => 'A3P5RG', 'private_key' => $taxpayer_private, 'certificate' => $cert_pem), $fake);
$jws = $v2->jws(array('nonce' => 'n', 'clientId' => 'A3P5RG'));
list($h, $p, $s) = explode('.', $jws);
$hdr = json_decode(HY_Moadian_Util::base64url_decode($h), true);
same('RS256', $hdr['alg'], 'JWS alg');
same(array('sigT'), $hdr['crit'], 'JWS crit');
check(openssl_verify("$h.$p", HY_Moadian_Util::base64url_decode($s), $taxpayer_public, OPENSSL_ALGO_SHA256) === 1, 'JWS signature verifies');
$x5c = openssl_x509_read("-----BEGIN CERTIFICATE-----\n" . chunk_split($hdr['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n");
check($x5c !== false, 'x5c is a valid DER certificate');
$jwe = HY_Moadian_V2::jwe('hello invoice', array('key' => $tax_org_public, 'id' => 'kid-9'));
$parts = explode('.', $jwe);
same(5, count($parts), 'JWE has 5 parts');
$cek = HY_Moadian_Util::rsa_oaep_sha256_decrypt(HY_Moadian_Util::base64url_decode($parts[1]), $tax_org_private);
$plain = openssl_decrypt(HY_Moadian_Util::base64url_decode($parts[3]), 'aes-256-gcm', $cek, OPENSSL_RAW_DATA, HY_Moadian_Util::base64url_decode($parts[2]), HY_Moadian_Util::base64url_decode($parts[4]), $parts[0]);
same('hello invoice', $plain, 'JWE decrypts with AAD = protected header');
same('kid-9', json_decode(HY_Moadian_Util::base64url_decode($parts[0]), true)['kid'], 'JWE kid');

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
