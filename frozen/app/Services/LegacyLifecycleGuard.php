<?php

namespace App\Services;

final class LegacyLifecycleGuard
{
    public static function reject(): void
    {
        if (config('lifecycle.root')) throw new \RuntimeException('Legacy backup/reset/restore is disabled for D5A. A writer fence does not certify a recovery transaction.');
    }
}
