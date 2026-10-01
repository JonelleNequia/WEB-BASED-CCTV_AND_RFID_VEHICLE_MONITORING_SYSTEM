<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Phase 1 (visitor model): a campus gate. Every gate records IN and OUT and
 * has its own camera (cameras.camera_role = code), reader and calibration.
 *
 * Records store the gate code ("gate-1"); the name ("Main Gate") is only for
 * display and can be renamed. The old station names "entrance" and "exit"
 * are still accepted as input and mean Gate 1 and Gate 2.
 */
class Gate extends Model
{
    /** Old station value => gate code (data before Phase 1, old clients and links). */
    public const LEGACY_CODES = ['entrance' => 'gate-1', 'exit' => 'gate-2'];

    /** Phase 3 (visitor model): gates use UHF readers only (NFC was dropped). */
    public const READER_TYPES = [
        'uhf_ethernet' => 'UHF (network reader)',
        'simulated' => 'Simulated (Test Scan only)',
    ];

    protected $fillable = [
        'code',
        'name',
        'sort_order',
        'is_active',
        'reader_type',
        'reader_name',
        'reader_manual',
        'reader_ip',
        'reader_port',
        'reader_transport',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'reader_manual' => 'boolean',
            'reader_port' => 'integer',
        ];
    }

    public function camera(): HasOne
    {
        return $this->hasOne(Camera::class, 'camera_role', 'code');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Active gates in display order.
     *
     * @return Collection<int, Gate>
     */
    public static function ordered(): Collection
    {
        return static::query()->active()->get();
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return static::ordered()->pluck('code')->all();
    }

    /**
     * code => name, for selects and labels.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return static::ordered()->pluck('name', 'code')->all();
    }

    /**
     * Turn any gate input (code, old "entrance"/"exit") into a gate code.
     * Unknown values return null.
     */
    public static function resolveCode(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if ($value === '') {
            return null;
        }

        $value = self::LEGACY_CODES[$value] ?? $value;

        return static::query()->where('code', $value)->exists() ? $value : null;
    }

    /**
     * Like resolveCode(), but falls back to the first gate.
     */
    public static function normalizeCode(?string $value): string
    {
        return static::resolveCode($value) ?? (static::codes()[0] ?? 'gate-1');
    }

    /**
     * Display name of a gate code (or an old station value).
     */
    public static function labelFor(?string $code): string
    {
        if (blank($code)) {
            return 'Unknown gate';
        }

        $resolved = self::LEGACY_CODES[strtolower((string) $code)] ?? (string) $code;

        // Per request (a table of 50 rows asks 50 times).
        return once(fn (): string => static::query()->where('code', $resolved)->value('name') ?? ucfirst(str_replace('-', ' ', (string) $code)));
    }

    /**
     * Next free code ("gate-3") for Settings › Add gate.
     */
    public static function nextCode(): string
    {
        $highest = static::query()->pluck('code')
            ->map(fn (string $code): int => (int) preg_replace('/\D+/', '', $code))
            ->max() ?? 0;

        return 'gate-'.($highest + 1);
    }

    public function readerTypeLabel(): string
    {
        return self::READER_TYPES[$this->reader_type] ?? ucfirst((string) $this->reader_type);
    }

    public function readerDisplayName(): string
    {
        return $this->reader_name ?: $this->name.' Reader';
    }
}
