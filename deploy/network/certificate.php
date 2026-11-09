<?php
// Public certificate diagnostics only. Never reads or exports CA keys.
try {
    $pem = file_get_contents($argv[1]);
    if (str_contains($pem, 'PRIVATE KEY') || substr_count($pem, 'BEGIN CERTIFICATE') !== 1) throw new RuntimeException('One public root certificate is required.');
    $info = openssl_x509_parse($pem);
    $kind = $argv[2] ?? 'root';
    if (! $info || ! in_array($kind, ['root', 'intermediate', 'leaf'], true)) throw new RuntimeException('Invalid certificate.');
    if ($kind !== 'leaf' && ! str_contains($info['extensions']['basicConstraints'] ?? '', 'CA:TRUE')) throw new RuntimeException('Invalid issuer certificate.');
    if ($kind === 'root' && $info['subject'] !== $info['issuer']) throw new RuntimeException('Invalid root certificate.');
    if ($kind === 'leaf' && ! str_contains($info['extensions']['subjectAltName'] ?? '', 'DNS:philcst.vms.internal')) throw new RuntimeException('Wrong HTTPS hostname.');
    if (time() < $info['validFrom_time_t'] || time() >= $info['validTo_time_t']) throw new RuntimeException('Certificate/clock is not current. Check time/expiry; do not regenerate the CA.');
    echo json_encode(['sha256' => openssl_x509_fingerprint($pem, 'sha256'), 'not_before' => gmdate('c', $info['validFrom_time_t']), 'not_after' => gmdate('c', $info['validTo_time_t']), 'expires_in_seconds' => $info['validTo_time_t'] - time(), 'kind' => $kind], JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }
