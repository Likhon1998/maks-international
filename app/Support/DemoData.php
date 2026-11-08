<?php

namespace App\Support;

class DemoData
{
    public static function taka(int|float|string|null $amount): string
    {
        $amount = (float) ($amount ?? 0);
        $n = (string) abs((int) round($amount));
        $last3 = substr($n, -3);
        $rest = substr($n, 0, -3);
        $grouped = $rest !== ''
            ? strrev(implode(',', str_split(strrev($rest), 2))).','.$last3
            : $last3;

        return ($amount < 0 ? '-৳ ' : '৳ ').$grouped;
    }
}
