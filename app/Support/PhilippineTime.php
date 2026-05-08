<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class PhilippineTime
{
    public const TIMEZONE = 'Asia/Manila';

    public static function todayDateString(): string
    {
        return Carbon::now(self::TIMEZONE)->toDateString();
    }

    public static function localDateString(?CarbonInterface $time = null): string
    {
        return ($time ? $time->copy() : Carbon::now())->setTimezone(self::TIMEZONE)->toDateString();
    }

    /**
     * @return array{local_start: Carbon, local_end: Carbon, storage_start: Carbon, storage_end: Carbon}
     */
    public static function todayWindow(): array
    {
        $localStart = Carbon::now(self::TIMEZONE)->startOfDay();
        $localEnd = $localStart->copy()->addDay();
        $storageTimezone = config('app.timezone', 'UTC');

        return [
            'local_start' => $localStart,
            'local_end' => $localEnd,
            'storage_start' => $localStart->copy()->setTimezone($storageTimezone),
            'storage_end' => $localEnd->copy()->setTimezone($storageTimezone),
        ];
    }

    public static function constrainToday($query, string $column): void
    {
        $window = self::todayWindow();
        $start = $column === 'created_at' ? $window['storage_start'] : $window['local_start'];
        $end = $column === 'created_at' ? $window['storage_end'] : $window['local_end'];

        $query->where($column, '>=', $start)
            ->where($column, '<', $end);
    }

    /**
     * @param  array<int, string>  $columns
     */
    public static function constrainTodayAny($query, array $columns): void
    {
        $window = self::todayWindow();

        $query->where(function ($query) use ($columns, $window): void {
            foreach ($columns as $column) {
                $query->orWhere(function ($query) use ($column, $window): void {
                    $start = $column === 'created_at' ? $window['storage_start'] : $window['local_start'];
                    $end = $column === 'created_at' ? $window['storage_end'] : $window['local_end'];

                    $query->where($column, '>=', $start)
                        ->where($column, '<', $end);
                });
            }
        });
    }
}
