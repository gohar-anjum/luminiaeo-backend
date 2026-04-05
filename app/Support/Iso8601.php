<?php

namespace App\Support;

use Carbon\CarbonInterface;

final class Iso8601
{
    public static function utcZ(?CarbonInterface $dt): ?string
    {
        if ($dt === null) {
            return null;
        }

        return $dt->clone()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
