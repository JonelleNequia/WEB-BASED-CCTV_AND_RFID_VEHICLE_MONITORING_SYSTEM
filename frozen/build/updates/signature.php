<?php
// Trusted verifier helper, not a script loaded from an update package. No writes.
try {
    $input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if (! extension_loaded('openssl')) { throw new RuntimeException('Trusted PHP OpenSSL extension unavailable'); }
    $key = openssl_pkey_get_public($input['public_key']);
    if ($key === false) { throw new RuntimeException('Invalid trusted public key'); }
    $details = openssl_pkey_get_details($key);
    if ($details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) { throw new RuntimeException('RSA key must be at least 2048 bits'); }
    $signature = base64_decode($input['signature'], true);
    $data = base64_decode($input['data'], true);
    if ($signature === false || $data === false) { throw new RuntimeException('Invalid signature encoding'); }
    // Only exactly 1 means verified; -1 is an error, not success.
    if (openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) { throw new RuntimeException('Signature verification failed'); }
    echo json_encode(['verified' => true], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Update signature rejected: '.$error->getMessage().PHP_EOL);
    exit(1);
}
