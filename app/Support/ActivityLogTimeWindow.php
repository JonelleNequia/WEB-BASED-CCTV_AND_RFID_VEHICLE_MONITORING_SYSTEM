<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** A daily Manila wall-clock window, independent of the selected dates. */
final class ActivityLogTimeWindow
{
    private function __construct(private ?string $from, private ?string $to) {}

    public static function rules(array $input): array
    {
        $valid = static fn (string $field, string $format): bool => is_string($input[$field] ?? null)
            && Validator::make([$field => $input[$field]], [$field => ['required', 'date_format:'.$format]])->passes();

        return [
            'date_from' => ['bail', 'nullable', 'date_format:Y-m-d', 'date'],
            'date_to' => ['bail', 'nullable', 'date_format:Y-m-d', 'date', Rule::when($valid('date_from', 'Y-m-d'), 'after_or_equal:date_from')],
            'time_from' => ['bail', 'nullable', 'date_format:H:i'],
            'time_to' => ['bail', 'nullable', 'date_format:H:i', function ($attribute, $value, $fail) use ($input, $valid): void {
                if ($valid('time_from', 'H:i') && is_string($value) && $input['time_from'] > $value) {
                    $fail('To Time must be on or after From Time. Overnight periods require separate selections with this daily-window filter.');
                }
            }],
        ];
    }

    public static function messages(): array
    {
        return ['date_from.date_format' => 'Enter a valid From date (YYYY-MM-DD).', 'date_to.date_format' => 'Enter a valid To date (YYYY-MM-DD).',
            'date_to.after_or_equal' => 'To date must be on or after From date.',
            'time_from.date_format' => 'Enter a valid From Time (HH:MM, 00:00–23:59).', 'time_to.date_format' => 'Enter a valid To Time (HH:MM, 00:00–23:59).'];
    }

    public static function forRequest(Request $request): self
    {
        $input = $request->only(['date_from', 'date_to', 'time_from', 'time_to']);
        Validator::make($input, self::rules($input), self::messages())->validate();

        return new self($request->filled('time_from') ? $request->string('time_from')->value() : null,
            $request->filled('time_to') ? $request->string('time_to')->value() : null);
    }

    /** Receives the same Manila occurrence timestamp used by the row and CSV. */
    public function includes(?string $at): bool
    {
        if ($this->from === null && $this->to === null) {
            return true;
        }
        if ($at === null) {
            return false;
        }
        $minute = substr($at, 11, 5);

        return ($this->from === null || $minute >= $this->from) && ($this->to === null || $minute <= $this->to);
    }

    public function label(): ?string
    {
        if ($this->from === null && $this->to === null) {
            return null;
        }
        $format = static fn (string $time): string => Carbon::createFromFormat('!H:i', $time, PhilippineTime::TIMEZONE)->format('g:i A');
        $range = match (true) {
            $this->from === null => 'through '.$format($this->to),
            $this->to === null => 'from '.$format($this->from),
            default => $format($this->from).' – '.$format($this->to),
        };

        return 'Daily '.$range.' (Asia/Manila; end minute included)';
    }
}
