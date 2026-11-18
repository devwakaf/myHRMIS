<?php

declare(strict_types=1);

namespace App\Domain\Core\Services;

use App\Domain\Core\Models\HrSettings;
use App\Domain\Core\Models\Organisation;
use App\Domain\Shared\Numbering\DocumentNumberGenerator;

/**
 * Employee numbers from the HR settings format (B1.15), e.g. "{CODE}-{#####}".
 * Tokens: {CODE} organisation short code, {YYYY} year of joining, {#…#} running
 * number padded to the number of #. The sequence is per organisation (not yearly).
 */
final class EmployeeNumberGenerator
{
    public function __construct(private readonly DocumentNumberGenerator $sequences) {}

    public function next(Organisation $organisation, ?string $joiningYear = null): string
    {
        $format = HrSettings::forOrganisation($organisation->id)->employee_no_format ?: '{CODE}-{#####}';
        $value = $this->sequences->nextValue('EMP', 0, $organisation->id);

        $result = preg_replace_callback('/\{(#+)\}/', fn (array $m) => str_pad((string) $value, strlen($m[1]), '0', STR_PAD_LEFT), $format);

        return strtr((string) $result, [
            '{CODE}' => $organisation->short_code,
            '{YYYY}' => $joiningYear ?? now()->format('Y'),
        ]);
    }
}
