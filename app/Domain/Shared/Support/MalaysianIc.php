<?php

declare(strict_types=1);

namespace App\Domain\Shared\Support;

use App\Domain\Shared\Support\Enums\Gender;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * MyKad number (12 digits: YYMMDD-PB-###G) parsed into date of birth, gender
 * and place-of-birth code (B3.1).
 *
 * Century rule: try 20YY; if that makes the person younger than the minimum
 * employment age today (or born in the future), use 19YY.
 * Gender rule: odd last digit = male, even = female (UNVERIFIED-STAT, open Q3).
 */
final class MalaysianIc
{
    private function __construct(
        public readonly string $digits,
        public readonly CarbonImmutable $dateOfBirth,
        public readonly Gender $gender,
        public readonly string $placeCode,
    ) {}

    public static function normalise(string $input): string
    {
        return (string) preg_replace('/[\s\-]/', '', $input);
    }

    /**
     * @throws InvalidArgumentException with a translation key as message
     */
    public static function parse(string $input, int $minEmploymentAge = 14, ?CarbonImmutable $today = null): self
    {
        $digits = self::normalise($input);
        if (preg_match('/^\d{12}$/', $digits) !== 1) {
            throw new InvalidArgumentException('core.validation.ic_format');
        }

        $today ??= CarbonImmutable::today();
        $yy = (int) substr($digits, 0, 2);
        $mm = (int) substr($digits, 2, 2);
        $dd = (int) substr($digits, 4, 2);

        $dob = null;
        foreach ([2000 + $yy, 1900 + $yy] as $year) {
            if (! checkdate($mm, $dd, $year)) {
                continue;
            }
            $candidate = CarbonImmutable::create($year, $mm, $dd)->startOfDay();
            if ($year >= 2000 && ($candidate->greaterThan($today) || $candidate->diffInYears($today) < $minEmploymentAge)) {
                continue;
            }
            $dob = $candidate;
            break;
        }

        if ($dob === null) {
            throw new InvalidArgumentException('core.validation.ic_date');
        }

        $last = (int) substr($digits, -1);

        return new self(
            $digits,
            $dob,
            $last % 2 === 1 ? Gender::Male : Gender::Female,
            substr($digits, 6, 2),
        );
    }

    public static function tryParse(?string $input, int $minEmploymentAge = 14, ?CarbonImmutable $today = null): ?self
    {
        if ($input === null || trim($input) === '') {
            return null;
        }

        try {
            return self::parse($input, $minEmploymentAge, $today);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function isKnownPlaceCode(): bool
    {
        return in_array((int) $this->placeCode, self::knownPlaceCodes(), true);
    }

    /**
     * Place-of-birth codes accepted without warning. UNVERIFIED-STAT (open Q3):
     * kept in config so it can be corrected without a code change.
     *
     * @return list<int>
     */
    public static function knownPlaceCodes(): array
    {
        /** @var list<int> $codes */
        $codes = config('myhrmis.ic_place_codes', []);

        return $codes;
    }

    public function formatted(): string
    {
        return substr($this->digits, 0, 6).'-'.substr($this->digits, 6, 2).'-'.substr($this->digits, 8);
    }

    public static function mask(?string $digits): ?string
    {
        if ($digits === null || $digits === '') {
            return $digits;
        }

        return str_repeat('*', max(0, strlen($digits) - 4)).substr($digits, -4);
    }
}
