<?php
// No Laravel bootstrap and no account/device data. SQLite probe is in memory.
try {
    $needed = ['ctype', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'json', 'libxml', 'mbstring', 'openssl', 'pcre', 'session', 'tokenizer', 'curl', 'gd', 'intl', 'pdo_sqlite', 'sqlite3', 'zip', 'Zend OPcache'];
    foreach ($needed as $extension) {
        if (! extension_loaded($extension)) { throw new RuntimeException('Missing PHP extension: '.$extension); }
    }
    if (! in_array('argon2id', password_algos(), true)) { throw new RuntimeException('Bundled PHP must support Argon2id'); }
    $hash = password_hash('Readiness-only synthetic phrase', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1]);
    if (! password_verify('Readiness-only synthetic phrase', $hash)) { throw new RuntimeException('Argon2id verification failed'); }
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE readiness (id INTEGER PRIMARY KEY); INSERT INTO readiness VALUES (1)');
    if ((int) $pdo->query('SELECT count(*) FROM readiness')->fetchColumn() !== 1) { throw new RuntimeException('SQLite probe failed'); }
    if (isset($argv[1])) {
        if (! is_file($argv[1])) { throw new RuntimeException('Configured local database is missing'); }
        $db = new PDO('sqlite:'.$argv[1], null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $db->exec('PRAGMA query_only=ON');
        if ($db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') { throw new RuntimeException('SQLite integrity check failed'); }
    }
    echo json_encode(['php' => PHP_VERSION, 'sqlite' => $pdo->query('SELECT sqlite_version()')->fetchColumn(), 'extensions' => 'passed', 'argon2id' => 'passed', 'database' => isset($argv[1]) ? 'read-only check passed' : 'memory probe passed']), PHP_EOL;
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage().PHP_EOL); exit(1); }
