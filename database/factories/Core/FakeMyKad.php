<?php

declare(strict_types=1);

namespace Database\Factories\Core;

use App\Domain\Shared\Support\Enums\Gender;
use Carbon\CarbonInterface;

/**
 * Format-valid but fictional MyKad numbers for factories and demo data.
 */
final class FakeMyKad
{
    /** Place codes used for fictional numbers (all in the accepted list). */
    private const PLACE_CODES = ['06', '10', '14', '03', '01', '08', '11', '07'];

    public static function make(CarbonInterface $dateOfBirth, Gender $gender, ?int $seed = null): string
    {
        $seed ??= random_int(0, 99999);
        $place = self::PLACE_CODES[$seed % count(self::PLACE_CODES)];
        $serial = str_pad((string) ($seed % 1000), 3, '0', STR_PAD_LEFT);

        $last = ($seed % 5) * 2; // even digit 0..8
        if ($gender === Gender::Male) {
            $last += 1; // odd digit 1..9
        }

        return $dateOfBirth->format('ymd').$place.$serial.$last;
    }
}
