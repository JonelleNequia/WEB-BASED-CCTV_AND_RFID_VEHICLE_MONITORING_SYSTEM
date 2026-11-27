<?php

return [
    // Set only by the offline installers; development routes retain their current origin.
    'named_https' => (bool) env('PHILCST_NAMED_HTTPS', false),
    'host' => 'philcst.vms.internal',
    'url' => 'https://philcst.vms.internal',
    'integration_port' => 8080,
    'lan_ip' => env('PHILCST_LAN_IP', '127.0.0.1'),
    'media_lease_seconds' => 30,
];
