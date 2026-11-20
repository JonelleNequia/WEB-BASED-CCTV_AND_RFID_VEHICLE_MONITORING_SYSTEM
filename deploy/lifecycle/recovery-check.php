<?php
// Installed trusted code only. Never bootstrap Laravel, include archive PHP,
// print decrypted values, or use an incoming php.ini/autoloader.
try {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $environment = Dotenv\Dotenv::parse(file_get_contents($input['environment']));
    $key = $environment['APP_KEY'] ?? '';
    $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
    if (! is_string($key) || strlen($key) !== 32) throw new RuntimeException('Required APP_KEY is invalid.');
    $crypt = new Illuminate\Encryption\Encrypter($key, 'AES-256-CBC');
    $db = new PDO('sqlite:file:'.str_replace('?', '%3F', $input['database']).'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA query_only=ON');
    $decrypted = 0; $plaintext = 0;
    foreach ($db->query('SELECT source_type,source_value,source_password FROM cameras') as $camera) {
        $value = $camera['source_password'];
        if (is_string($value) && $value !== '') {
            $decoded = json_decode(base64_decode($value, true) ?: '', true);
            if (is_array($decoded) && isset($decoded['iv'], $decoded['value'], $decoded['mac'])) {
                $crypt->decryptString($value); $decrypted++;
            } else {
                // The app's legacy plaintext compatibility is reported, not re-encrypted.
                $plaintext++;
            }
        }
        if (! in_array($camera['source_type'], ['none', 'webcam', 'rtsp', 'http'], true)) throw new RuntimeException('Unsupported recovered camera source type.');
        if (in_array($camera['source_type'], ['rtsp', 'http'], true) && $camera['source_value'] !== ''
            && ! preg_match('~\A(?:rtsp|rtsps|https?)://[^\r\n]+\z~i', $camera['source_value'])) {
            throw new RuntimeException('Recovered camera source is not a supported network URL.');
        }
        if ($camera['source_type'] === 'webcam' && ! preg_match('/\A\d+\z/', (string) $camera['source_value'])) throw new RuntimeException('Invalid recovered webcam index.');
    }
    $issuer = rtrim($input['web'], '/\\').'/tls/pki/authorities/local/';
    $root = file_get_contents($issuer.'root.crt');
    $rootInfo = openssl_x509_parse($root);
    if (! $rootInfo || ! str_contains($rootInfo['extensions']['basicConstraints'] ?? '', 'CA:TRUE')
        || $rootInfo['subject'] !== $rootInfo['issuer'] || time() < $rootInfo['validFrom_time_t'] || time() >= $rootInfo['validTo_time_t']
        || ! openssl_x509_check_private_key($root, file_get_contents($issuer.'root.key'))
        || openssl_x509_verify($root, openssl_pkey_get_public($root)) !== 1) throw new RuntimeException('TLS root key/certificate/clock validation failed.');
    $intermediate = file_get_contents($issuer.'intermediate.crt');
    $intermediateInfo = openssl_x509_parse($intermediate);
    if (! $intermediateInfo || ! str_contains($intermediateInfo['extensions']['basicConstraints'] ?? '', 'CA:TRUE')
        || time() < $intermediateInfo['validFrom_time_t'] || time() >= $intermediateInfo['validTo_time_t']
        || ! openssl_x509_check_private_key($intermediate, file_get_contents($issuer.'intermediate.key'))
        || openssl_x509_verify($intermediate, openssl_pkey_get_public($root)) !== 1) throw new RuntimeException('TLS intermediate validation failed.');
    $fingerprint = openssl_x509_fingerprint($root, 'sha256');
    $receipt = json_decode(file_get_contents($input['tls_identity']), true, flags: JSON_THROW_ON_ERROR);
    if (! hash_equals($fingerprint, $receipt['sha256'] ?? '')
        || ! hash_equals($fingerprint, openssl_x509_fingerprint(file_get_contents($input['public_root']), 'sha256'))) throw new RuntimeException('TLS root identity receipt mismatch.');
    $proof = [];
    foreach ($input['public_keys'] ?? [] as $id => $pem) {
        $public = openssl_pkey_get_public($pem);
        $info = $public ? openssl_pkey_get_details($public) : false;
        if (! $info || $info['type'] !== OPENSSL_KEYTYPE_RSA || $info['bits'] < 2048) throw new RuntimeException('Invalid update verification key.');
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $info['key']), true);
        if (! is_string($der) || ! hash_equals(hash('sha256', $der), (string) $id)) throw new RuntimeException('Update verification key fingerprint mismatch.');
        $proof[] = $id;
    }
    echo json_encode(['decrypted_camera_secrets' => $decrypted, 'legacy_plaintext_secrets' => $plaintext,
        'tls_root_sha256' => $fingerprint, 'update_keys_validated' => count($proof)], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $exception) {
    // Ciphertext, passwords, certificate keys and vendor diagnostics never reach public output.
    fwrite(STDERR, "Recovery key, camera-source or issuer validation failed; keep maintenance and inspect private recovery evidence.\n");
    exit(1);
}
