<?php

namespace App\Support;

/** CSV has no type metadata: keep identifiers literal, neutralize formula text. */
final class SpreadsheetCsv
{
    public static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/\A(?:[\x00-\x20]*[=+\-@]|[\t\r\n])/', $text) ? "'".$text : $text;
    }
}
