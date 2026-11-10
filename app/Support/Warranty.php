<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class Warranty
{
    public static function days(?string $text): ?int
    {
        $text = strtolower(trim((string) $text));
        if ($text === '' || ! preg_match('/(\d+)\s*(year|years|yr|yrs|month|months|mo|day|days)/i', $text, $match)) {
            return null;
        }

        $n = (int) $match[1];
        $unit = strtolower($match[2]);

        return match (true) {
            str_starts_with($unit, 'year') || $unit === 'yr' || $unit === 'yrs' => $n * 365,
            str_starts_with($unit, 'month') || $unit === 'mo' => $n * 30,
            default => $n,
        };
    }

    public static function expiresAt(?string $text, ?Carbon $start): ?Carbon
    {
        $days = self::days($text);
        if ($days === null || ! $start) {
            return null;
        }

        return $start->copy()->addDays($days);
    }

    public static function status(?string $text, ?Carbon $start, ?Carbon $asOf = null, bool $claim = false): string
    {
        $expiry = self::expiresAt($text, $start);
        if (! $expiry) {
            return '—';
        }

        $asOf ??= now();
        if ($asOf->gt($expiry)) {
            return 'Expired';
        }

        return $claim ? 'Under warranty' : 'Active';
    }
}
