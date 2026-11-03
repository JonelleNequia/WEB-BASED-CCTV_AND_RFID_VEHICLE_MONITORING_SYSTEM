<?php

return [
    // password_verify accepts existing bcrypt/Argon hashes during migration.
    'driver' => in_array('argon2id', password_algos(), true) ? 'argon2id' : 'bcrypt',
    'bcrypt' => ['rounds' => (int) env('BCRYPT_ROUNDS', 12), 'verify' => false, 'limit' => 72],
    'argon' => ['memory' => (int) env('ARGON_MEMORY', 65536), 'threads' => 1, 'time' => (int) env('ARGON_TIME', 3), 'verify' => false],
    'rehash_on_login' => true,
];
